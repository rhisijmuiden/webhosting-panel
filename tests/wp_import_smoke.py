"""End to end check of the WordPress import wizard (run by smoke.sh as root, via smoke.d/wp-import.sh).

A WordPress site is installed on SOURCE and dressed up as if it came from
another host (old address, old absolute paths, remote DB_HOST, a Redis drop-in,
cache folder). Its files are zipped below public_html/ and its database is
exported like phpMyAdmin does (with CREATE DATABASE / USE). Both files are
imported into TARGET with wp_import.inspect + wp_import.run, and the result
must work on TARGET with its own database.

Needs internet access to wordpress.org. Usage: python3 tests/wp_import_smoke.py wpsrc.test import.test
"""

import os
import pwd
import re
import subprocess
import sys
import time
import uuid
import zipfile

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wp_import as wpi  # noqa: E402
from whp_agent.migration import wordpress_fix as fix  # noqa: E402

source, target = sys.argv[1], sys.argv[2]
OLD_URL = "https://www.oldhost.example"
OLD_ROOT = "/home/old123/public_html"


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


src = wordpress.site_info(source)
php = ["/usr/bin/php" + src["php"], wordpress.WP_CLI]
wordpress.as_user(src, [*php, "core", "download", "--quiet"], cwd=src["docroot"], timeout=600)
wordpress.as_user(src, [*php, "config", "create", "--dbname=wpsrc", "--dbuser=wpsrc", "--prompt=dbpass", "--quiet"],
                  cwd=src["docroot"], input_text="Src-Db-Pass-2026\n", timeout=60)
wordpress.wp(source, ["core", "install", f"--url=http://{source}", "--title=Imported Smoke", "--admin_user=beheer",
                      "--admin_email=info@example.test", "--admin_password=Old-Pass-123456", "--skip-email"], timeout=300)
# What a site at another host looks like: its address and absolute paths everywhere.
wordpress.wp(source, ["option", "update", "upload_path", f"{OLD_ROOT}/wp-content/uploads"])
wordpress.wp(source, ["option", "add", "whp_smoke_paths", '{"cache_dir":"' + OLD_ROOT + '/wp-content/cache/x"}', "--format=json"])
wordpress.wp(source, ["post", "create", "--post_status=publish", "--post_title=Old links",
                      f"--post_content=<img src=\"{OLD_URL}/wp-content/uploads/a.jpg\"> <a href=\"http://oldhost.example/page\">x</a>"])
wordpress.wp(source, ["option", "update", "home", OLD_URL])
wordpress.wp(source, ["option", "update", "siteurl", OLD_URL])

# The website zip: everything below public_html/, with the wp-config.php of the old host.
work = os.path.join(wpi.UPLOAD_DIR)
os.makedirs(work, mode=0o750, exist_ok=True)
import_id = str(uuid.uuid4())
site_zip = os.path.join(work, f"{import_id}-site.upload")
cfg = open(os.path.join(src["docroot"], "wp-config.php")).read()
cfg = fix.set_config(cfg, "DB_HOST", "mysql.oldhost.example")
cfg = cfg.replace("<?php", f"<?php\ndefine( 'WP_CACHE', true );\ndefine( 'WPCACHEHOME', '{OLD_ROOT}/wp-content/plugins/wp-super-cache/' );", 1)
with zipfile.ZipFile(site_zip, "w", zipfile.ZIP_DEFLATED) as zf:
    for dirpath, _dirs, files in os.walk(src["docroot"]):
        for f in files:
            full = os.path.join(dirpath, f)
            rel = os.path.relpath(full, src["docroot"])
            if rel == "wp-config.php":
                zf.writestr("public_html/wp-config.php", cfg)
            else:
                zf.write(full, "public_html/" + rel)
    zf.writestr("public_html/wp-content/object-cache.php", "<?php // Redis drop-in of the old host\n")
    zf.writestr("public_html/wp-content/cache/page.html", "cached page")
    zf.writestr("public_html/.user.ini", f"auto_prepend_file = '{OLD_ROOT}/wordfence-waf.php'\n")
    zf.writestr("logs/access_log", "not website data")

# The database export, like phpMyAdmin makes it.
dump = subprocess.run(["mariadb-dump", "--protocol=socket", "-uroot", "--single-transaction", "--skip-lock-tables", "wpsrc"],
                      capture_output=True, timeout=600)
step("source database exported", dump.returncode == 0, dump.stderr.decode()[-300:])
with zipfile.ZipFile(os.path.join(work, f"{import_id}-database.upload"), "w", zipfile.ZIP_DEFLATED) as zf:
    zf.writestr("old123_wp.sql", b"CREATE DATABASE IF NOT EXISTS `old123_wp`;\nUSE `old123_wp`;\n" + dump.stdout)

state = wait(wpi.wp_import_inspect({"import": import_id, "domain": target}))
step("check job succeeds", state["status"] == "succeeded", state.get("error"))
report = state["result"]
step("check passes", report["ok"], report["checks"])
step("check finds WordPress below public_html", report["site"]["folder"] == "public_html" and report["site"]["version"], report["site"])
step("check reads the old address from the dump", report["database"]["home"] == OLD_URL, report["database"])
step("check finds the old path", any(p["old"] == OLD_ROOT and p["likely"] for p in report["paths"]), report["paths"])
step("check lists the Redis drop-in", any(c["file"] == "wp-content/object-cache.php" for c in report["site"]["cache"]))

state = wait(wpi.wp_import_run({"import": import_id, "domain": target, "new_url": report["target"]["url"],
                                "paths": [p["old"] for p in report["paths"] if p["likely"]], "urls": [u["old"] for u in report["urls"]],
                                "disable": ["wp-content/object-cache.php"]}))
step("import job succeeds", state["status"] == "succeeded", (state.get("error"), state.get("log", [])[-8:]))
result = state["result"]
dst = wordpress.site_info(target)
docroot = os.path.realpath(dst["docroot"])
new_cfg = open(os.path.join(docroot, "wp-config.php")).read()
step("wp-config.php points at the new database", fix.config_value(new_cfg, "DB_NAME") == result["database"]["name"]
     and fix.config_value(new_cfg, "DB_HOST") == "localhost" and fix.config_value(new_cfg, "DB_PASSWORD") != "Src-Db-Pass-2026")
step("wp-config.php has the new paths", OLD_ROOT not in new_cfg and f"{docroot}/wp-content/plugins/wp-super-cache/" in new_cfg)
step("the new address is set", option(target, "home") == f"http://{target}" and option(target, "siteurl") == f"http://{target}",
     option(target, "home"))
step("old paths are changed in the database", option(target, "upload_path") == f"{docroot}/wp-content/uploads")
serial = wordpress.wp(target, ["option", "get", "whp_smoke_paths", "--format=json"]).stdout
step("serialized values are changed too", docroot in serial and OLD_ROOT not in serial, serial)
content = wordpress.wp(target, ["post", "list", "--post_type=post", "--fields=post_content", "--format=csv"]).stdout
step("old links in posts point at the new address", "oldhost.example" not in content and f"http://{target}/wp-content/uploads/a.jpg" in content, content)
step("the Redis drop-in is switched off", os.path.isfile(os.path.join(docroot, "wp-content/object-cache.php.whp-disabled"))
     and not os.path.exists(os.path.join(docroot, "wp-content/object-cache.php")))
step("cache folders and files outside WordPress are not copied", not os.path.exists(os.path.join(docroot, "wp-content/cache"))
     and not os.path.exists(os.path.join(docroot, "public_html")) and not os.path.exists(os.path.join(docroot, "logs")))
step(".user.ini gets the new path", OLD_ROOT not in open(os.path.join(docroot, ".user.ini")).read())
owner = pwd.getpwnam(dst["user"]).pw_uid
step("files belong to the website user", all(os.lstat(os.path.join(d, f)).st_uid == owner
                                            for d, _, fs in os.walk(docroot) for f in fs))
grants = subprocess.run(["mariadb", "-uroot", "-N", "-e", f"SHOW GRANTS FOR '{result['database']['user']}'@'localhost'"],
                        capture_output=True, text=True).stdout
step("the new database user only has its own database", "wpsrc" not in grants and result["database"]["name"] in grants, grants)
page = subprocess.run(["curl", "-s", "--resolve", f"{target}:80:127.0.0.1", f"http://{target}/"], capture_output=True, text=True, timeout=60).stdout
step("the imported website answers", "Imported Smoke" in page, page[:300])
step("the health check saw it", 200 <= result["health"] < 400, result["health"])
step("uploads are removed by discard", wpi.wp_import_discard({"import": import_id})["removed"] == 3)
