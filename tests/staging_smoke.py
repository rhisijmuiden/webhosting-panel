"""End to end check of WordPress staging (run by smoke.sh as root, after clone_smoke.py).

test.wp.test is a clone of wp.test. It is marked as staging, changed, and pushed
back to wp.test: the change must be live, wp.test must keep its own address,
database and media, and the backup made before the push must put it all back.
Usage: python3 tests/staging_smoke.py test.wp.test wp.test
"""

import os
import pwd
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wp_staging  # noqa: E402
from whp_agent.migration import wordpress_fix as fix  # noqa: E402

staging, live = sys.argv[1], sys.argv[2]


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {detail}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def wait(job):
    for _ in range(900):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(1)
    raise SystemExit("job did not finish")


def option(domain, name):
    return wordpress.wp(domain, ["option", "get", name]).stdout.strip()


def put(path, text, user):
    with open(path, "w") as fh:
        fh.write(text)
    pw = pwd.getpwnam(user)
    os.chown(path, pw.pw_uid, pw.pw_gid)


s_site, l_site = wordpress.site_info(staging), wordpress.site_info(live)
live_cfg = os.path.join(l_site["docroot"], "wp-config.php")
live_db = fix.config_value(open(live_cfg).read(), "DB_NAME")
title_before = option(live, "blogname")

out = wp_staging.staging_prepare({"staging": staging})
step("the copy gets the staging mu-plugin", os.path.isfile(os.path.join(s_site["docroot"], wp_staging.MU_PLUGIN)), out)
step("WordPress loads with the staging mu-plugin", option(staging, "blog_public") == "0")

# Work on staging; meanwhile a photo is uploaded on live.
wordpress.wp(staging, ["option", "update", "blogname", "Staged title"])
put(os.path.join(s_site["docroot"], "staging-only.txt"), "new on staging", s_site["user"])
uploads = os.path.join(l_site["docroot"], "wp-content", "uploads")
os.makedirs(uploads, exist_ok=True)
put(os.path.join(uploads, "live-only.jpg"), "jpg", l_site["user"])

plan = wp_staging.staging_plan({"staging": staging, "live": live})
step("plan has no problems and lists the staging tables", not plan["problems"] and any(t["name"].endswith("options") for t in plan["tables"]), plan)

state = wait(wp_staging.staging_push({"staging": staging, "live": live}))
step("push job succeeds", state["status"] == "succeeded", state.get("error"))
result = state["result"]
step("the change is live", option(live, "blogname") == "Staged title")
step("live keeps its own address", option(live, "home") == f"http://{live}", option(live, "home"))
step("live keeps its own database", fix.config_value(open(live_cfg).read(), "DB_NAME") == live_db)
pushed = os.path.join(l_site["docroot"], "staging-only.txt")
step("new files are live and belong to the live user", os.path.isfile(pushed) and os.stat(pushed).st_uid == pwd.getpwnam(l_site["user"]).pw_uid)
step("media uploaded on live is kept", os.path.isfile(os.path.join(uploads, "live-only.jpg")))
step("the staging mu-plugin is not pushed", not os.path.exists(os.path.join(l_site["docroot"], wp_staging.MU_PLUGIN)))
page = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--resolve", f"{live}:80:127.0.0.1", f"http://{live}/"],
                      capture_output=True, text=True, timeout=60).stdout
step("the live website answers", page == "200", page)
backups = wp_staging.staging_backups({"live": live})["backups"]
step("a backup was made before the push", [b["id"] for b in backups][:1] == [result["backup"]], backups)

state = wait(wp_staging.staging_restore({"live": live, "backup": result["backup"]}))
step("restore job succeeds", state["status"] == "succeeded", state.get("error"))
step("the backup puts the old title back", option(live, "blogname") == title_before, option(live, "blogname"))
step("files the push added are gone again", not os.path.exists(pushed))
print("staging smoke test passed")
