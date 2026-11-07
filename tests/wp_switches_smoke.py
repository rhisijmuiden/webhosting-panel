"""End to end check of the WordPress quick switches on the real install made by
wp_smoke.py (run by smoke.d/wp-switches.sh as root). Usage: python3 tests/wp_switches_smoke.py wp.test
"""

import os
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import wp_switches as sw  # noqa: E402

domain = sys.argv[1]
site = wordpress.site_info(domain)
P = {"domain": domain}


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {detail}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def get(path="/"):
    proc = subprocess.run(["curl", "-s", "-o", "-", "-w", "\n%{http_code}", "--resolve", f"{domain}:80:127.0.0.1",
                           f"http://{domain}{path}"], capture_output=True, text=True, timeout=60)
    body, _, code = proc.stdout.rpartition("\n")
    return int(code or 0), body


st = sw.wp_switches(P)
step("wp.switches reads the state", st["maintenance"] is False and st["indexing"] in (True, False), st)

# Maintenance mode: visitors get the 503 page, off again gives the site back.
sw.wp_maintenance_set({**P, "enabled": True, "title": "Smoke onderhoud", "message": "Zo terug", "lang": "nl"})
code, body = get()
step("maintenance on: 503 with our page", code == 503 and "Smoke onderhoud" in body, f"{code} {body[:200]}")
step("maintenance mu-plugin belongs to the site user",
     os.stat(os.path.join(site["docroot"], "wp-content/mu-plugins/whp-maintenance.php")).st_uid
     == os.stat(site["docroot"]).st_uid)
step("wp.switches sees maintenance", sw.wp_switches(P)["maintenance"] is True)
sw.wp_maintenance_set({**P, "enabled": False})
code, _ = get()
step("maintenance off: site answers again", code in (200, 301, 302), code)

# Debug mode: errors go to logs/wp-debug.log, not to the screen and not under the docroot.
st = sw.wp_debug_set({**P, "enabled": True})
step("debug on with the private log", st["debug"] and st["debug_log"] == "panel", st)
probe = os.path.join(site["docroot"], "whp-smoke-notice.php")
wordpress.as_user(site, ["/bin/sh", "-c", "cat > whp-smoke-notice.php"], cwd=site["docroot"],
                  input_text="<?php require __DIR__.'/wp-load.php'; trigger_error('whp smoke warning', E_USER_WARNING); echo 'done';\n")
code, body = get("/whp-smoke-notice.php")
os.unlink(probe)
step("errors are not shown to visitors", code == 200 and "whp smoke warning" not in body, f"{code} {body[:200]}")
log = sw.wp_debug_log({**P, "q": "whp smoke"})
step("wp.debug_log finds the warning", log["exists"] and any(e["level"] == "warning" for e in log["entries"]), log)
step("no public wp-content/debug.log", not os.path.exists(os.path.join(site["docroot"], "wp-content/debug.log")))
sw.wp_debug_log_clear(P)
step("wp.debug_log_clear empties it", sw.wp_debug_log(P)["entries"] == [])
st = sw.wp_debug_set({**P, "enabled": False})
step("debug off", st["debug"] is False, st)

# Indexing.
step("indexing off", sw.wp_indexing_set({**P, "enabled": False})["indexing"] is False)
out = wordpress.wp(domain, ["option", "get", "blog_public"]).stdout.strip()
step("blog_public is 0", out.endswith("0"), out)
step("indexing on", sw.wp_indexing_set({**P, "enabled": True})["indexing"] is True)

# Caches.
res = sw.wp_cache_clear(P)
step("clear cache: every step ran", res["ok"], res)
