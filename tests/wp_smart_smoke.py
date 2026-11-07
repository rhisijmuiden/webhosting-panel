"""End to end check of the smart WordPress updates on a real install (run as root by
tests/smoke.d/wp-smart-updates.sh, after wp_smoke.py made wp.test).

Real Chromium screenshots and Pillow comparison: an update that changes nothing
visible is kept; an update after which the page looks different (a mu-plugin
inverts the page colours once hello-dolly is no longer 1.6) is rolled back.
Needs internet access to wordpress.org. Usage: python3 tests/wp_smart_smoke.py wp.test
"""

import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wp_smart as ws  # noqa: E402

domain = sys.argv[1]
site = wordpress.site_info(domain)
_, wp_dir = wordpress.require_install(domain, "")


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {json.dumps(detail)[-2500:]}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def wait(job):
    for _ in range(1200):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(1)
    raise SystemExit("job did not finish")


def old_hello():
    wordpress.wp(domain, ["plugin", "install", "hello-dolly", "--version=1.6", "--force", "--activate"], timeout=300)


def hello_version():
    return wordpress.wp(domain, ["plugin", "get", "hello-dolly", "--field=version"], timeout=60).stdout.strip().splitlines()[-1]


caps = ws.wp_smart_check({})
step("Chromium, Pillow and the shot user are there", caps["visual"], caps)

# 1. Nothing visible changes: the update is kept and the screenshots are there.
old_hello()
state = wait(ws.wp_smart_update({"domain": domain, "run": "smoke00001", "mode": "all", "pages": ["/"],
                                 "viewports": ["desktop", "mobile"], "threshold": 10}))
res = state["result"]
step("smart update job succeeds", state["status"] == "succeeded", state)
step("update is kept when the site looks the same", res["outcome"] == "updated", res)
step("hello-dolly was updated", hello_version() != "1.6", hello_version())
step("screenshots were compared", res["visual"] and res["max_score"] is not None and res["max_score"] < 10, res["pages"])
images = res["pages"][0]["images"]
step("before, after and diff images", sorted(images) == ["after", "before", "diff"], images)
img = ws.wp_smart_image({"run": "smoke00001", "name": images["before"]})
step("images are JPEG", img["data"][:4] == "/9j/", img["data"][:8])
step("raw PNGs are cleaned up", not (ws.VISUAL / "smoke00001" / "raw").exists())
step("no work folders are left", not any(ws.WORK.iterdir()))

# 2. The page looks different after the update: rolled back to the snapshot.
mu = Path(wp_dir) / "wp-content" / "mu-plugins" / "whp-smoke-look.php"
wordpress.as_user(site, ["/bin/sh", "-c", f"mkdir -p wp-content/mu-plugins && cat > {mu.relative_to(wp_dir)}"], cwd=wp_dir,
                  input_text="<?php\nadd_action('wp_head', function () {\n"
                             "  $f = WP_PLUGIN_DIR . '/hello-dolly/hello.php';\n"
                             "  if (is_file($f) && strpos(file_get_contents($f), 'Version: 1.6') === false) {\n"
                             "    echo '<style>html{filter:invert(1) hue-rotate(180deg)!important;background:#fff}</style>';\n"
                             "  }\n});\n", timeout=60)
old_hello()
state = wait(ws.wp_smart_update({"domain": domain, "run": "smoke00002", "mode": "all", "pages": ["/"],
                                 "viewports": ["desktop"], "threshold": 10}))
res = state["result"]
step("visual difference rolls the update back", res["outcome"] == "rolled_back" and res["reason"] == "visual", res)
step("the difference was above the threshold", (res["max_score"] or 0) > 10, res["max_score"])
step("hello-dolly is back on 1.6", hello_version() == "1.6", hello_version())
entry = res["pages"][0]
step("the restored site looks like before", "restored" in entry["images"] and entry["restored_score"] < 10, entry)

# 3. Notify only changes nothing.
state = wait(ws.wp_smart_update({"domain": domain, "run": "smoke00003", "mode": "notify"}))
res = state["result"]
step("notify only reports the pending update", res["outcome"] == "pending" and any(p["name"] == "hello-dolly" for p in res["pending"]["plugins"]), res)
step("notify only leaves hello-dolly alone", hello_version() == "1.6", hello_version())

mu.unlink()
for run in ("smoke00001", "smoke00002", "smoke00003"):
    ws.wp_smart_forget({"run": run})
step("forget removes the images", not any((ws.VISUAL / r).exists() for r in ("smoke00001", "smoke00002")))
print("Smart WordPress updates smoke test passed")
