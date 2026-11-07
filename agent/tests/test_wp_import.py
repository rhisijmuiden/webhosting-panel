"""wp_import.*: zip checks, the database scan, wp-config.php rewrite and the import
job, with MariaDB and WP-CLI mocked out. Files are really extracted (site_worker
runs as the test user).

The real import (MariaDB, WP-CLI) runs in the Debian 13 smoke test (tests/wp_import_smoke.py).
"""

import io
import json
import os
import shutil
import sys
import tempfile
import time
import unittest
import zipfile
from pathlib import Path
from subprocess import CompletedProcess
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import client_backup as cb  # noqa: E402
from whp_agent.actions import wp_import as wpi  # noqa: E402
from whp_agent.migration import wordpress_fix as wpfix  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

REAL_POPEN = __import__("subprocess").Popen
IMPORT = "0f8b2c4e-1a2b-4c3d-8e9f-0123456789ab"
OLD_ROOT = "/home/u123/domains/oud.nl/public_html"
WP_CONFIG = f"""<?php
define( 'DB_NAME', 'u123_wp' );
define( 'DB_USER', 'u123_wp' );
define( 'DB_PASSWORD', 'oud-geheim' );
define( 'DB_HOST', 'mysql.oudehoster.nl' );
define( 'WP_CACHE', true );
define( 'WPCACHEHOME', '{OLD_ROOT}/wp-content/plugins/wp-super-cache/' );
$table_prefix = 'wp_';
require_once ABSPATH . 'wp-settings.php';
"""
SQL = f"""-- phpMyAdmin SQL Dump
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET @@SESSION.SQL_LOG_BIN= 0;
SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5',
'3E11FA47-71CA-11E1-9E33-C80AA9429562:6-9';
CREATE DATABASE IF NOT EXISTS `u123_wp` DEFAULT CHARACTER SET utf8mb4;
USE `u123_wp`;
CREATE TABLE `xyz_options` (`option_id` bigint, `option_name` varchar(191), `option_value` longtext, `autoload` varchar(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `xyz_options` VALUES (1,'siteurl','https://www.oud.nl','yes'),(2,'home','https://www.oud.nl','yes'),(3,'upload_path','{OLD_ROOT}/wp-content/uploads','yes');
CREATE TABLE `xyz_posts` (`ID` bigint) ENGINE=InnoDB;
INSERT INTO `xyz_posts` VALUES (1,'a:1:{{s:4:\\"path\\";s:47:\\"{OLD_ROOT}/wp-content/x\\";}}');
/*!50003 CREATE*/ /*!50017 DEFINER=`u123`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON xyz_posts FOR EACH ROW SET @a=1 */;
"""


def make_site_zip(path, folder="public_html", config=WP_CONFIG, extra=None):
    with zipfile.ZipFile(path, "w") as zf:
        p = f"{folder}/" if folder else ""
        zf.writestr(f"{p}index.php", "<?php require __DIR__ . '/wp-blog-header.php';")
        zf.writestr(f"{p}wp-includes/version.php", "<?php\n$wp_version = '6.8.3';\n")
        zf.writestr(f"{p}wp-admin/index.php", "<?php")
        zf.writestr(f"{p}wp-content/themes/twentytwentyfive/style.css", "/* theme */")
        zf.writestr(f"{p}wp-content/cache/page.html", "cached")
        zf.writestr(f"{p}wp-content/object-cache.php", "<?php // redis")
        zf.writestr(f"{p}wp-content/advanced-cache.php", f"<?php include '{OLD_ROOT}/wp-content/plugins/wp-super-cache/wp-cache-phase1.php';")
        zf.writestr(f"{p}wp-content/mu-plugins/endurance-page-cache.php", "<?php")
        zf.writestr(f"{p}.user.ini", f"auto_prepend_file = '{OLD_ROOT}/wordfence-waf.php'\n")
        zf.writestr(f"{p}wp-config-sample.php", "<?php\ndefine( 'DB_NAME', 'database_name_here' );\n$table_prefix = 'wp_';\n")
        if config is not None:
            zf.writestr(f"{p}wp-config.php", config)
        zf.writestr("logs/access.log", "not website data")
        for name, data in (extra or {}).items():
            zf.writestr(name, data)


def make_db_zip(path, sql=SQL, name="u123_wp.sql"):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(name, sql)


def wait(job):
    for _ in range(400):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


class HelperTest(unittest.TestCase):
    def test_clean_names(self):
        self.assertEqual(wpi.clean_name("public_html/wp-config.php"), "public_html/wp-config.php")
        self.assertEqual(wpi.clean_name("public_html\\wp-config.php"), "public_html/wp-config.php")
        self.assertEqual(wpi.clean_name("dir/"), "dir")
        for bad in ("/etc/passwd", "../x", "a/../../x", "C:/x", "a/./b"):
            self.assertIsNone(wpi.clean_name(bad), bad)

    def test_find_root_takes_the_shallowest(self):
        names = ["site/wp/wp-includes/version.php", "site/wp/backup/old/wp-includes/version.php", "site/wp/wp-admin/x"]
        self.assertEqual(wpi.find_root(names), "site/wp")
        self.assertEqual(wpi.find_root(["wp-includes/version.php"]), "")
        self.assertIsNone(wpi.find_root(["index.html"]))

    def test_old_paths(self):
        text = (f"'{OLD_ROOT}/wp-content/uploads' \"/var/www/vhosts/oud.nl/httpdocs/wp-includes/x\" "
                "https://oud.nl/wp-content/a.jpg href=\"/nl/wp-content/b.jpg\" /usr/share/php/wp-content/x")
        found = wpi.old_paths(text)
        self.assertEqual(found, {OLD_ROOT: 1, "/var/www/vhosts/oud.nl/httpdocs": 1})

    def test_prefixes(self):
        self.assertEqual(wpi.wp_prefixes(["wp_options", "wp_posts", "old_options", "x_users"]), ["wp_"])
        self.assertEqual(wpi.wp_prefixes(["options", "posts"]), [""])

    def test_url_variants_and_pairs(self):
        self.assertEqual(wpi.url_variants("https://www.oud.nl/"),
                         ["https://www.oud.nl", "http://www.oud.nl", "https://oud.nl", "http://oud.nl"])
        self.assertEqual(wpi.url_variants("http://oud.nl/blog")[:2], ["http://oud.nl/blog", "https://oud.nl/blog"])
        pairs = wpi.url_pairs(["https://oud.nl", "https://www.oud.nl", "https://nieuw.nl"], "https://nieuw.nl")
        self.assertEqual(pairs[0], ("https://www.oud.nl", "https://nieuw.nl"))
        self.assertIn(("https:\\/\\/oud.nl", "https:\\/\\/nieuw.nl"), pairs)
        self.assertNotIn(("https://nieuw.nl", "https://nieuw.nl"), pairs)

    def test_sql_filter(self):
        state = {}
        lines = SQL.encode().splitlines(keepends=True)
        out = b"".join(x for x in (wpi.filter_sql_line(line, state) for line in lines) if x is not None)
        self.assertNotIn(b"CREATE DATABASE", out)
        self.assertNotIn(b"USE `u123_wp`", out)
        self.assertNotIn(b"GTID_PURGED", out)
        self.assertNotIn(b"3E11FA47", out, "the continuation line of a multi-line SET is skipped too")
        self.assertNotIn(b"SQL_LOG_BIN", out)
        self.assertNotIn(b"DEFINER", out)
        self.assertNotIn(b"utf8mb4_0900_ai_ci", out)
        self.assertIn(b"utf8mb4_unicode_520_ci", out)
        self.assertIn(b"INSERT INTO `xyz_posts`", out)
        data = b"INSERT INTO `t` VALUES ('USE `x`; DEFINER=`a`@`b` utf8mb4_0900_ai_ci');\n"
        self.assertEqual(wpi.filter_sql_line(data, {}), data)

    def test_scan_sql(self):
        scan = wpi.scan_sql(io.BytesIO(SQL.encode()))
        self.assertEqual(scan["tables"], ["xyz_options", "xyz_posts"])
        self.assertEqual(scan["urls"]["xyz_"], {"siteurl": "https://www.oud.nl", "home": "https://www.oud.nl"})
        self.assertEqual(scan["paths"][OLD_ROOT], 2)
        self.assertEqual((scan["create_database"], scan["use_database"], scan["super_statements"]), (1, 1, 2))
        self.assertTrue(scan["mysql8_collation"])
        with self.assertRaises(ActionError):
            wpi.scan_sql(io.BytesIO(b"<!DOCTYPE html><html><body>Login</body></html>\n"))

    def test_fix_config(self):
        db = {"name": "nieuw_nl_import_1a2b", "user": "nieuw_nl_import_1a2b", "password": "n'w"}
        out = wpi.fix_config(WP_CONFIG, db, "xyz_", wpi.path_pairs([OLD_ROOT], "/var/www/vhosts/nieuw.nl/public"),
                             wpi.url_pairs(["https://www.oud.nl"], "https://nieuw.nl"))
        self.assertEqual(wpfix.config_value(out, "DB_NAME"), "nieuw_nl_import_1a2b")
        self.assertEqual(wpfix.config_value(out, "DB_PASSWORD"), "n'w")
        self.assertEqual(wpfix.config_value(out, "DB_HOST"), "localhost")
        self.assertIn("$table_prefix = 'xyz_';", out)
        self.assertEqual(wpfix.config_value(out, "WPCACHEHOME"), "/var/www/vhosts/nieuw.nl/public/wp-content/plugins/wp-super-cache/")
        sample = wpi.fix_config("<?php\n// sample\nrequire_once ABSPATH . 'wp-settings.php';\n", db, "wp_", [], [])
        self.assertEqual(wpfix.config_value(sample, "DB_USER"), "nieuw_nl_import_1a2b")
        self.assertIn("$table_prefix = 'wp_';", sample)

    def test_check_zip_refuses_unsafe_entries(self):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as zf:
            zf.writestr("../../etc/cron.d/x", "boom")
        with zipfile.ZipFile(buf) as zf, self.assertRaises(ActionError):
            wpi.check_zip(zf, 10 ** 9)
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
            zf.writestr("big.bin", b"\0" * (12 * 1024 ** 2))
        with zipfile.ZipFile(buf) as zf, self.assertRaises(ActionError) as cm:
            wpi.check_zip(zf, 10 ** 10)
        self.assertIn("compression ratio", cm.exception.message)


class ImportJobTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        root = base / "vhosts" / "nieuw.nl"
        (root / "public").mkdir(parents=True)
        (root / "public" / "index.html").write_text('<!doctype html><meta charset="utf-8"><title>nieuw.nl</title><p>nieuw.nl</p>\n')
        self.site = {"domain": "nieuw.nl", "id": "1", "user": "s_nieuw_nl", "php": "8.4", "root": str(root),
                     "docroot": str(root / "public"), "ssl": "active", "aliases": ["www.nieuw.nl"], "suspended": False,
                     "slice": "whp-sites.slice"}
        self.uploads = base / "uploads"
        self.uploads.mkdir()
        state = base / "state"
        self.dbs = {}
        self.patches = [
            mock.patch.object(paths, "STATE_DIR", str(state)),
            mock.patch.object(paths, "JOBS_DIR", str(state / "jobs")),
            mock.patch.object(wpi, "UPLOAD_DIR", str(self.uploads)),
            mock.patch.object(wpi, "STATE", str(state / "reports")),
            mock.patch.object(wpi.wordpress, "site_info", side_effect=lambda d: dict(self.site)),
            mock.patch.object(wpi, "_uid", return_value=(os.getuid(), os.getgid())),
            mock.patch.object(wpi, "cb_du_mb", return_value=42),
            mock.patch.object(cb, "worker_cmd", side_effect=lambda user, args: [sys.executable, "-I", cb.WORKER, *args]),
            mock.patch("whp_agent.actions.apps._pwnam", side_effect=lambda u: __import__("pwd").getpwuid(os.getuid())),
            mock.patch.object(wpi.databases, "_load", side_effect=lambda: dict(self.dbs)),
            mock.patch.object(wpi.databases, "create_for_site", side_effect=self.create_db),
            mock.patch.object(wpi.databases, "drop", side_effect=lambda name, domain: self.dbs.pop(name, None)),
            mock.patch.object(wpi.databases, "owned_by", side_effect=lambda d: sorted(n for n, e in self.dbs.items() if e["domain"] == d)),
            mock.patch.object(wpi, "run", return_value=CompletedProcess([], 0, "", "")),
            mock.patch("whp_agent.actions.wordpress.health", return_value=200),
        ]
        for p in self.patches:
            p.start()
        self.wp_calls = []
        self.loaded = {}

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def create_db(self, domain, label, name=None, pw=None):
        name = name or f"nieuw_nl_{label}_ffff"
        self.dbs[name] = {"domain": domain, "label": label}
        return {"name": name, "user": name, "password": "nieuw-wachtwoord", "host": "localhost"}

    def fake_wp(self, domain, args, path="", **kw):
        self.wp_calls.append(args)
        return CompletedProcess(args, 0, "2\n", "")

    def upload(self, site_kw=None, db=True):
        make_site_zip(self.uploads / f"{IMPORT}-site.upload", **(site_kw or {}))
        if db:
            make_db_zip(self.uploads / f"{IMPORT}-database.upload")

    def inspect(self):
        state = wait(wpi.wp_import_inspect({"import": IMPORT, "domain": "nieuw.nl"}))
        self.assertEqual(state["status"], "succeeded", state)
        return state["result"]

    def fake_popen(self, argv, stdin=None, **kw):
        if argv[0] != wpi.MARIADB:
            return REAL_POPEN(argv, stdin=stdin, **kw)
        test = self

        class P:
            def __init__(self):
                self.buf = io.BytesIO()
                self.stdin = self
                self.stderr = io.BytesIO(b"")

            def write(self, b):
                self.buf.write(b)

            def close(self):
                test.loaded["sql"] = self.buf.getvalue()
                test.loaded["argv"] = argv
                cnf = [a for a in argv if a.startswith("--defaults-extra-file=")][0].split("=", 1)[1]
                test.loaded["cnf"] = Path(cnf).read_text()

            def wait(self):
                return 0

        return P()

    def test_inspect_reports_everything_the_wizard_shows(self):
        self.upload()
        r = self.inspect()
        self.assertTrue(r["ok"], r["checks"])
        states = {c["key"]: c["state"] for c in r["checks"]}
        self.assertEqual(states["wordpress_found"], "ok")
        self.assertEqual(states["config_found"], "ok")
        self.assertEqual(states["prefix_matches"], "warn", "wp-config says wp_, the dump has xyz_")
        self.assertEqual(r["site"]["folder"], "public_html")
        self.assertEqual(r["site"]["version"], "6.8.3")
        self.assertEqual(r["site"]["old_db"], {"db_name": "u123_wp", "db_user": "u123_wp", "db_host": "mysql.oudehoster.nl", "password_set": True})
        self.assertEqual(r["database"]["prefix"], "xyz_")
        self.assertEqual(r["database"]["home"], "https://www.oud.nl")
        self.assertEqual(r["paths"][0]["old"], OLD_ROOT)
        self.assertTrue(r["paths"][0]["likely"])
        self.assertGreaterEqual(r["paths"][0]["count"], 4)
        self.assertEqual(r["target"]["url"], "https://www.nieuw.nl", "old site used www, the new one has the www alias")
        self.assertIn({"old": "https://www.oud.nl", "new": "https://www.nieuw.nl"}, r["urls"])
        self.assertEqual({c["file"] for c in r["site"]["cache"]},
                         {"wp-content/object-cache.php", "wp-content/advanced-cache.php", "wp-content/mu-plugins/endurance-page-cache.php"})
        self.assertEqual(r["target"]["content"], [], "the placeholder page does not count")
        self.assertTrue(r["target"]["database"].startswith("nieuw_nl_import_"))

    def test_inspect_blocks_a_zip_without_wordpress(self):
        with zipfile.ZipFile(self.uploads / f"{IMPORT}-site.upload", "w") as zf:
            zf.writestr("index.html", "hi")
        make_db_zip(self.uploads / f"{IMPORT}-database.upload")
        r = self.inspect()
        self.assertFalse(r["ok"])
        with self.assertRaises(ActionError):
            wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl"})

    def test_inspect_needs_both_files(self):
        self.upload(db=False)
        with self.assertRaises(ActionError) as cm:
            wpi.wp_import_inspect({"import": IMPORT, "domain": "nieuw.nl"})
        self.assertEqual(cm.exception.code, "not_found")

    def test_full_import(self):
        self.upload()
        r = self.inspect()
        with mock.patch.object(wpi.wordpress, "wp", side_effect=self.fake_wp), \
                mock.patch.object(wpi.subprocess, "Popen", side_effect=self.fake_popen):
            state = wait(wpi.wp_import_run({
                "import": IMPORT, "domain": "nieuw.nl", "new_url": "https://www.nieuw.nl",
                "paths": [OLD_ROOT], "urls": [u["old"] for u in r["urls"]], "disable": ["wp-content/object-cache.php"],
                "extra": [["https://cdn.oud.nl", "https://www.nieuw.nl"]],
            }))
        self.assertEqual(state["status"], "succeeded", state)
        res = state["result"]
        doc = Path(self.site["docroot"])
        # Files: the WordPress folder became the document root, caches and logs left out.
        self.assertTrue((doc / "wp-includes" / "version.php").is_file())
        self.assertFalse((doc / "public_html").exists())
        self.assertFalse((doc / "wp-content" / "cache").exists())
        self.assertFalse((doc / "logs").exists())
        self.assertFalse((doc / "index.html").exists(), "the placeholder page is gone")
        self.assertTrue((doc / "wp-content" / "object-cache.php.whp-disabled").is_file())
        self.assertEqual(res["disabled"], ["wp-content/object-cache.php"])
        # wp-config.php points at the new database, with the prefix of the dump and the new paths.
        cfg = (doc / "wp-config.php").read_text()
        self.assertEqual(wpfix.config_value(cfg, "DB_NAME"), res["database"]["name"])
        self.assertEqual(wpfix.config_value(cfg, "DB_PASSWORD"), "nieuw-wachtwoord")
        self.assertEqual(wpfix.config_value(cfg, "DB_HOST"), "localhost")
        self.assertIn("$table_prefix = 'xyz_';", cfg)
        self.assertNotIn(OLD_ROOT, cfg)
        self.assertNotIn(OLD_ROOT, (doc / ".user.ini").read_text())
        self.assertNotIn(OLD_ROOT, (doc / "wp-content" / "advanced-cache.php").read_text())
        # The dump went in as the new database's own user, cleaned up.
        self.assertIn(f'user="{res["database"]["name"]}"', self.loaded["cnf"])
        self.assertNotIn(b"CREATE DATABASE", self.loaded["sql"])
        self.assertNotIn(b"DEFINER", self.loaded["sql"])
        self.assertIn(b"INSERT INTO `xyz_options`", self.loaded["sql"])
        # Addresses first (longest first), then paths, then the extra pair.
        sr = [c for c in self.wp_calls if c[0] == "search-replace"]
        self.assertEqual(sr[0][1:3], ["https://www.oud.nl", "https://www.nieuw.nl"])
        self.assertIn([OLD_ROOT, os.path.realpath(self.site["docroot"])], [c[1:3] for c in sr])
        self.assertEqual(sr[-1][1:3], ["https://cdn.oud.nl", "https://www.nieuw.nl"])
        self.assertIn(["config", "shuffle-salts"], self.wp_calls)
        self.assertEqual(res["health"], 200)
        self.assertEqual(list(self.dbs), [res["database"]["name"]])

    def test_failed_import_puts_the_old_website_back(self):
        self.upload()
        (Path(self.site["docroot"]) / "index.html").write_text("<h1>my old site</h1>")
        self.inspect()
        with self.assertRaises(ActionError):
            wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl"})  # has files, replace not chosen

        def broken_popen(argv, **kw):
            if argv[0] != wpi.MARIADB:
                return REAL_POPEN(argv, **kw)
            raise ActionError("command_failed", "Importing the database failed: ERROR 1064")

        with mock.patch.object(wpi.wordpress, "wp", side_effect=self.fake_wp), \
                mock.patch.object(wpi.subprocess, "Popen", side_effect=broken_popen):
            state = wait(wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl", "replace": True}))
        self.assertEqual(state["status"], "failed")
        self.assertIn("1064", state["error"]["message"])
        doc = Path(self.site["docroot"])
        self.assertEqual(sorted(os.listdir(doc)), ["index.html"])
        self.assertEqual((doc / "index.html").read_text(), "<h1>my old site</h1>")
        self.assertEqual(self.dbs, {}, "the new database is dropped again")
        self.assertEqual([n for n in os.listdir(self.site["root"]) if n.startswith(".whp-import-previous")], [])

    def test_wp_config_from_sample_and_previous_import_database_dropped(self):
        self.dbs["nieuw_nl_import_0001"] = {"domain": "nieuw.nl", "label": "import"}
        self.upload(site_kw={"config": None, "folder": ""})
        r = self.inspect()
        self.assertEqual({c["key"]: c["state"] for c in r["checks"]}["config_found"], "warn")
        self.assertEqual(r["target"]["previous_databases"], ["nieuw_nl_import_0001"])
        with mock.patch.object(wpi.wordpress, "wp", side_effect=self.fake_wp), \
                mock.patch.object(wpi.subprocess, "Popen", side_effect=self.fake_popen):
            state = wait(wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl", "new_url": "https://nieuw.nl"}))
        self.assertEqual(state["status"], "succeeded", state)
        cfg = (Path(self.site["docroot"]) / "wp-config.php").read_text()
        self.assertEqual(wpfix.config_value(cfg, "DB_NAME"), state["result"]["database"]["name"])
        self.assertIn("$table_prefix = 'xyz_';", cfg)
        self.assertEqual(state["result"]["dropped_databases"], ["nieuw_nl_import_0001"])

    def test_run_only_accepts_reported_choices(self):
        self.upload()
        self.inspect()
        with self.assertRaises(ActionError):
            wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl", "new_url": "https://evil.example"})
        with self.assertRaises(ActionError):
            wpi.wp_import_run({"import": IMPORT, "domain": "nieuw.nl", "extra": [["ab", "x"]]})

    def test_discard(self):
        self.upload()
        self.inspect()
        self.assertEqual(wpi.wp_import_discard({"import": IMPORT})["removed"], 3)
        self.assertEqual(os.listdir(self.uploads), [])


if __name__ == "__main__":
    unittest.main()
