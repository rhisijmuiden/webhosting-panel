"""End to end check of the WordPress toolkit on a real install (run by smoke.sh as root).

Needs internet access to wordpress.org. Usage: python3 tests/wp_smoke.py wp.test
"""

import json
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402

domain = sys.argv[1]
site = wordpress.site_info(domain)


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


def curl(*args):
    return subprocess.run(["curl", "-s", "--resolve", f"{domain}:80:127.0.0.1", *args],
                          capture_output=True, text=True, timeout=60).stdout


# A WordPress install made by the site user, with an outdated plugin.
wordpress.as_user(site, ["/usr/bin/php" + site["php"], wordpress.WP_CLI, "core", "download", "--quiet"],
                  cwd=site["docroot"], timeout=600)
proc = wordpress.as_user(site, ["/usr/bin/php" + site["php"], wordpress.WP_CLI, "config", "create",
                                "--dbname=wp_smoke", "--dbuser=wp_smoke", "--prompt=dbpass", "--quiet"],
                         cwd=site["docroot"], input_text="Smoke-Db-Pass-2026\n", timeout=60)
wordpress.wp(domain, ["core", "install", f"--url=http://{domain}", "--title=Smoke", "--admin_user=beheer",
                      "--admin_email=info@example.test", "--admin_password=Old-Pass-123456", "--skip-email"], timeout=300)
wordpress.wp(domain, ["plugin", "install", "hello-dolly", "--version=1.6", "--activate"], timeout=300)

found = wpa.wp_detect({"domain": domain})["installs"]
step("wp.detect finds the install", [i["path"] for i in found] == [""], found)
status = wpa.wp_status({"domain": domain})
step("wp.status sees the outdated plugin", any(p["name"] == "hello-dolly" and p["update"] for p in status["plugins"]), status)
step("files written by WP-CLI are readable for nginx",
     oct(os.stat(os.path.join(site["docroot"], "wp-content/plugins/hello-dolly")).st_mode)[-3:] in ("755", "775"))

cron = wpa.wp_cron_set({"domain": domain, "mode": "system", "interval": 5})
step("wp.cron_set system enables the timer", cron["mode"] == "system" and cron["timer_active"], cron)
unit = cron["timer"].replace(".timer", ".service")
subprocess.run(["systemctl", "start", unit], timeout=120)
result = subprocess.run(["systemctl", "show", "-p", "Result", "--value", unit], capture_output=True, text=True).stdout.strip()
step("WP-cron service runs as the site user", result == "success", result)
cfg = wordpress.wp(domain, ["config", "get", "DISABLE_WP_CRON"], timeout=60).stdout.strip()
step("DISABLE_WP_CRON is set", cfg in ("1", "true"), cfg)

out = wpa.wp_set_password({"domain": domain, "user_id": 1})
check = wordpress.wp(domain, ["eval", "echo wp_check_password(rtrim(fgets(STDIN)), get_userdata(1)->user_pass, 1) ? 'yes' : 'no';"],
                     input_text=out["password"] + "\n", timeout=60).stdout.strip()
step("wp.set_password sets a working password", check.endswith("yes"), check)

link = wpa.wp_login_link({"domain": domain})["url"]
headers = curl("-D", "-", "-o", "/dev/null", link)
step("login link logs in and redirects to wp-admin", "wordpress_logged_in" in headers and "/wp-admin/" in headers, headers)
headers = curl("-D", "-", "-o", "/dev/null", link)
step("login link works only once", "wordpress_logged_in" not in headers, headers)

state = wait(wpa.wp_update({"domain": domain, "core": False, "themes": False, "translations": False}))
step("wp.update job succeeds", state["status"] == "succeeded", json.dumps(state)[-1500:])
res = state["result"]
step("hello-dolly was updated", any(u["name"] == "hello-dolly" for u in res["updated"]["plugins"]), res)
step("site healthy after the update", 200 <= res["health"]["after"] < 500, res["health"])
step("a snapshot was made", len(wpa.wp_snapshots({"domain": domain})["snapshots"]) == 1)

back = wpa.wp_cron_set({"domain": domain, "mode": "wordpress"})
step("wp.cron_set wordpress removes the timer", back["mode"] == "wordpress", back)
print("WordPress toolkit smoke test passed")
