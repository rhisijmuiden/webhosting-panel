"""End to end check of subdomains and the clone tool (run by smoke.sh as root, after wp_smoke.py).

Clones the real WordPress of wp.test into its subdomain test.wp.test and checks
that the copy has its own database, its own addresses and works.
Usage: python3 tests/clone_smoke.py wp.test test.wp.test
"""

import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import site_clone  # noqa: E402
from whp_agent.migration import wordpress_fix as fix  # noqa: E402

source, target = sys.argv[1], sys.argv[2]


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


plan = site_clone.clone_plan({"source": source, "target": target})
step("plan finds the WordPress and its database", plan["installs"] and plan["installs"][0]["kind"] == "wordpress"
     and plan["installs"][0]["db_name"] == "wp_smoke", plan)

state = wait(site_clone.clone_start({"source": source, "target": target}))
step("clone job succeeds", state["status"] == "succeeded", state.get("error"))
result = state["result"]
new_db = result["installs"][0]["database"]
step("the copy has a database of its own", new_db.startswith("test_wp_test_clone_"), result)

src, dst = wordpress.site_info(source), wordpress.site_info(target)
cfg = open(os.path.join(dst["docroot"], "wp-config.php")).read()
step("wp-config.php points at the new database", fix.config_value(cfg, "DB_NAME") == new_db)
step("files belong to the subdomain's own user",
     os.stat(os.path.join(dst["docroot"], "wp-config.php")).st_uid != os.stat(os.path.join(src["docroot"], "wp-config.php")).st_uid)
home = wordpress.wp(target, ["option", "get", "home"]).stdout.strip()
step("the copy has its own address", home == f"http://{target}", home)
step("the original keeps its address", wordpress.wp(source, ["option", "get", "home"]).stdout.strip() == f"http://{source}")
step("the copy is hidden from search engines", wordpress.wp(target, ["option", "get", "blog_public"]).stdout.strip() == "0")
page = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--resolve", f"{target}:80:127.0.0.1", f"http://{target}/"],
                      capture_output=True, text=True, timeout=60).stdout
step("the copy answers", page == "200", page)

again = site_clone.clone_start
try:
    again({"source": source, "target": target})
    step("a second clone without replace is refused", False)
except Exception as exc:  # ActionError: conflict
    step("a second clone without replace is refused", "already has files" in str(exc), str(exc))
state = wait(site_clone.clone_start({"source": source, "target": target, "replace": True}))
step("clone with replace succeeds", state["status"] == "succeeded", state.get("error"))
step("the earlier clone database is dropped", new_db in state["result"]["dropped_databases"], state["result"])
print("clone smoke test passed")
