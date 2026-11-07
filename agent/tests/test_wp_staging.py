"""wp.staging_*: helpers, the push job (backup, files, database, addresses) and the
automatic restore, with rsync, MariaDB and WP-CLI mocked out.

The real push runs in the Debian 13 smoke test (tests/staging_smoke.py).
"""

import json
import os
import shutil
import sys
import tempfile
import time
import unittest
from pathlib import Path
from subprocess import CompletedProcess
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402
from whp_agent.actions import wp_staging as st  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def config(db, prefix="wp_"):
    return f"""<?php
define( 'DB_NAME', '{db}' );
define( 'DB_USER', '{db}' );
define( 'DB_PASSWORD', 'pw-{db}' );
define( 'DB_HOST', 'localhost' );
$table_prefix = '{prefix}';
"""


def wait(job):
    for _ in range(400):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


class HelperTest(unittest.TestCase):
    def test_table_prefix(self):
        self.assertEqual(st.table_prefix(config("x", "shop_")), "shop_")
        self.assertIsNone(st.table_prefix("<?php\n$table_prefix = $foo;\n"))

    def test_suggested_keep_only_live_data_tables(self):
        tables = ["wp_options", "wp_posts", "wp_users", "wp_usermeta", "wp_wc_orders", "wp_wc_order_stats",
                  "wp_woocommerce_order_items", "wp_comments", "other_users", "wp_actionscheduler_actions"]
        keep = st.suggested_keep(tables, "wp_")
        self.assertIn("wp_wc_orders", keep)
        self.assertIn("wp_users", keep)
        self.assertIn("wp_woocommerce_order_items", keep)
        self.assertNotIn("wp_options", keep)
        self.assertNotIn("wp_posts", keep)
        self.assertNotIn("other_users", keep)

    def test_home_base(self):
        self.assertEqual(st.home_base("https://www.Klant.nl/blog"), "https://www.klant.nl")
        self.assertEqual(st.home_base("http://klant.nl"), "http://klant.nl")
        self.assertIsNone(st.home_base("klant.nl"))

    def test_push_rsync_keeps_live_config_and_uploads(self):
        argv = st.rsync_push_argv("/s/public", "/l/public", "s_klant_nl", True)
        self.assertIn("--exclude=/wp-config.php", argv)
        self.assertIn("--exclude=/" + st.MU_PLUGIN, argv)
        self.assertIn("--exclude=/wp-content/uploads/", argv)
        self.assertIn("--delete", argv)
        self.assertIn("--safe-links", argv)
        self.assertIn("--chown=s_klant_nl:s_klant_nl", argv)
        self.assertEqual(argv[-2:], ["/s/public/", "/l/public/"])
        self.assertNotIn("--exclude=/wp-content/uploads/", st.rsync_push_argv("/s", "/l", "u", False))

    def test_pairs_turn_staging_into_live(self):
        pairs = st.push_pairs(["staging.klant.nl"], "https://www.klant.nl")
        self.assertIn(("https://staging.klant.nl", "https://www.klant.nl"), pairs)
        self.assertIn(("https:\\/\\/staging.klant.nl", "https:\\/\\/www.klant.nl"), pairs)


class PushTest(unittest.TestCase):
    LIVE, STAGING = "klant.nl", "staging.klant.nl"

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.sites = {}
        for domain, db in ((self.LIVE, "klant_nl_wp_1a2b"), (self.STAGING, "staging_klant_nl_clone_9f3c")):
            root = base / "vhosts" / domain
            doc = root / "public"
            (doc / "wp-includes").mkdir(parents=True)
            (doc / "wp-content" / "uploads").mkdir(parents=True)
            (root / "tmp").mkdir()
            (doc / "wp-config.php").write_text(config(db))
            (doc / "wp-includes" / "version.php").write_text("<?php\n$wp_version = '6.8.1';\n")
            self.sites[domain] = {"domain": domain, "id": domain.replace(".", "_"), "user": "s_" + domain.replace(".", "_"),
                                  "php": "8.4", "root": str(root), "docroot": str(doc), "ssl": "active",
                                  "aliases": ["www.klant.nl"] if domain == self.LIVE else [], "suspended": False,
                                  "slice": "whp-sites.slice"}
        self.live_doc = Path(self.sites[self.LIVE]["docroot"])
        self.stag_doc = Path(self.sites[self.STAGING]["docroot"])
        (self.live_doc / "index.php").write_text("live v1")
        (self.live_doc / "only-live.txt").write_text("old")
        (self.live_doc / "wp-content/uploads/live-photo.jpg").write_text("jpg")
        (self.stag_doc / "index.php").write_text("staging v2")
        (self.stag_doc / "wp-content/uploads/new-photo.jpg").write_text("jpg2")
        (self.stag_doc / "wp-content/mu-plugins").mkdir()
        (self.stag_doc / st.MU_PLUGIN).write_text("<?php // staging")
        (self.stag_doc / ".htaccess").write_text(f"# {self.sites[self.STAGING]['docroot']}/x https://staging.klant.nl/\n")
        self.tables = {"klant_nl_wp_1a2b": ["wp_options", "wp_posts", "wp_wc_orders"],
                       "staging_klant_nl_clone_9f3c": ["wp_options", "wp_posts", "wp_wc_orders", "wp_newplugin"]}
        state = base / "state"
        self.patches = [
            mock.patch.object(paths, "STATE_DIR", str(state)),
            mock.patch.object(paths, "JOBS_DIR", str(state / "jobs")),
            mock.patch.object(st.wordpress, "site_info", side_effect=lambda d: dict(self.sites[d])),
            mock.patch.object(st.clone, "_uid", return_value=(os.getuid(), os.getgid())),
            mock.patch.object(st.clone, "_du_mb", return_value=12),
            mock.patch.object(st, "run", side_effect=self.fake_run),
            mock.patch.object(st, "_mysql", side_effect=self.fake_mysql),
            mock.patch.object(st.subprocess, "run", side_effect=self.fake_import),
            mock.patch.object(st.wordpress, "wp", side_effect=self.fake_wp),
            mock.patch.object(wpa, "health", side_effect=lambda url: self.health.pop(0) if self.health else 200),
        ]
        for p in self.patches:
            p.start()
        self.calls, self.wp_calls, self.imports = [], [], []
        self.health = [200, 200]
        self.fail_import = False

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    # --- fakes ---------------------------------------------------------------------

    def fake_run(self, argv, **kw):
        self.calls.append(list(argv))
        if argv[0] == "rsync":
            src, dst = argv[-2], argv[-1]
            excludes = [a.split("=", 1)[1].strip("/") for a in argv if a.startswith("--exclude=")]
            delete = "--delete" in argv
            os.makedirs(dst, exist_ok=True)
            if delete:
                for item in os.listdir(dst):
                    if item not in excludes and not any(e.startswith(item + "/") for e in excludes):
                        full = os.path.join(dst, item)
                        shutil.rmtree(full) if os.path.isdir(full) else os.unlink(full)

            def ignore(d, names):
                rel = os.path.relpath(d, src)
                return [n for n in names if os.path.normpath(os.path.join(rel, n)).lstrip("./") in excludes]

            shutil.copytree(src, dst, dirs_exist_ok=True, ignore=ignore)
        elif argv[0] == st.clone.MARIADB_DUMP:
            out = [a for a in argv if a.startswith("--result-file=")][0].split("=", 1)[1]
            db_at = argv.index("--") + 1
            tables = argv[db_at + 1:] or self.tables[argv[db_at]]
            Path(out).write_text("".join(f"/*!50017 DEFINER=`x`@`localhost`*/ DROP TABLE IF EXISTS `{t}`;\n" for t in tables)
                                 + f"-- from {argv[db_at]}\n")
        return CompletedProcess(argv, 0, "", "")

    def fake_mysql(self, inst, sql, timeout=120):
        if sql.startswith("SELECT"):
            return [[t, "1.0"] for t in self.tables[inst["db_name"]]]
        self.calls.append(["sql", inst["db_name"], sql])
        return []

    def fake_import(self, argv, stdin=None, **kw):
        sql = stdin.read().decode()
        cnf = [a for a in argv if a.startswith("--defaults-extra-file=")][0].split("=", 1)[1]
        user = [ln for ln in Path(cnf).read_text().splitlines() if ln.startswith("user=")][0]
        self.imports.append({"sql": sql, "user": user})
        db = "klant_nl_wp_1a2b"
        for t in __import__("re").findall(r"DROP TABLE IF EXISTS `([^`]+)`", sql):
            if t not in self.tables[db]:
                self.tables[db].append(t)

        class Res:
            returncode = 1 if self.fail_import else 0
            stderr = b"ERROR 1062: Duplicate entry" if self.fail_import else b""

        return Res()

    def fake_wp(self, domain, args, path="", **kw):
        self.wp_calls.append((domain, list(args)))
        out = "3\n" if args[0] == "search-replace" else ""
        if args[:2] == ["option", "get"]:
            out = {"home": f"https://{'www.klant.nl' if domain == self.LIVE else domain}", "blog_public": "1"}.get(args[2], "")
        return CompletedProcess(args, 0, out, "")

    # --- tests -------------------------------------------------------------------------

    def test_plan(self):
        plan = st.staging_plan({"staging": self.STAGING, "live": self.LIVE})
        self.assertEqual(plan["problems"], [])
        self.assertEqual([t["name"] for t in plan["tables"]], self.tables["staging_klant_nl_clone_9f3c"])
        self.assertFalse(next(t for t in plan["tables"] if t["name"] == "wp_newplugin")["on_live"])
        self.assertEqual(plan["suggested_keep"], ["wp_wc_orders"])
        self.assertTrue(plan["woocommerce"])
        self.assertEqual(plan["live_db"], "klant_nl_wp_1a2b")
        self.assertNotIn("_password", json.dumps(plan))

    def test_prefix_mismatch_and_same_database_are_problems(self):
        (self.stag_doc / "wp-config.php").write_text(config("staging_klant_nl_clone_9f3c", "x_"))
        self.assertIn("table prefixes differ", st.staging_plan({"staging": self.STAGING, "live": self.LIVE})["problems"][0])
        (self.stag_doc / "wp-config.php").write_text(config("klant_nl_wp_1a2b"))
        self.assertIn("database of the live website", st.staging_plan({"staging": self.STAGING, "live": self.LIVE})["problems"][0])
        with self.assertRaises(ActionError) as cm:
            st.staging_push({"staging": self.STAGING, "live": self.LIVE})
        self.assertEqual(cm.exception.code, "conflict")

    def test_bad_params(self):
        for params in ({"staging": self.LIVE, "live": self.LIVE}, {"staging": self.STAGING, "live": self.LIVE, "files": False, "database": False},
                       {"staging": self.STAGING, "live": self.LIVE, "keep_tables": ["wp_x; DROP"]}):
            with self.assertRaises(ActionError):
                st.staging_push(params)

    def test_full_push(self):
        state = wait(st.staging_push({"staging": self.STAGING, "live": self.LIVE, "keep_tables": ["wp_wc_orders"]}))
        self.assertEqual(state["status"], "succeeded", state)
        r = state["result"]
        # A backup of live came first, with its own database user, and is listed.
        backups = st.staging_backups({"live": self.LIVE})["backups"]
        self.assertEqual([b["id"] for b in backups], [r["backup"]])
        self.assertEqual(backups[0]["tables"], ["wp_options", "wp_posts", "wp_wc_orders"])
        bdir = Path(paths.STATE_DIR, "staging", "backups", self.LIVE, r["backup"])
        self.assertEqual((bdir / "files" / "index.php").read_text(), "live v1")
        self.assertNotIn("DEFINER", (bdir / "database.sql").read_text())
        # Files: staging content live, live config kept, live-only media kept, marker not pushed.
        self.assertEqual((self.live_doc / "index.php").read_text(), "staging v2")
        self.assertIn("klant_nl_wp_1a2b", (self.live_doc / "wp-config.php").read_text())
        self.assertFalse((self.live_doc / "only-live.txt").exists())
        self.assertTrue((self.live_doc / "wp-content/uploads/live-photo.jpg").exists())
        self.assertTrue((self.live_doc / "wp-content/uploads/new-photo.jpg").exists())
        self.assertFalse((self.live_doc / st.MU_PLUGIN).exists())
        htaccess = (self.live_doc / ".htaccess").read_text()
        self.assertIn(self.sites[self.LIVE]["docroot"], htaccess)
        self.assertIn("https://www.klant.nl/", htaccess)
        # Database: kept tables not dumped, loaded as the live user, addresses back to live.
        dump = [c for c in self.calls if c[0] == st.clone.MARIADB_DUMP and "staging_klant_nl_clone_9f3c" in c][0]
        self.assertEqual(dump[dump.index("--") + 1:], ["staging_klant_nl_clone_9f3c", "wp_options", "wp_posts", "wp_newplugin"])
        self.assertEqual(self.imports[0]["user"], 'user="klant_nl_wp_1a2b"')
        self.assertNotIn("DEFINER", self.imports[0]["sql"])
        sr = [a for d, a in self.wp_calls if d == self.LIVE and a[0] == "search-replace"]
        self.assertIn(["search-replace", "https://staging.klant.nl", "https://www.klant.nl", "--all-tables-with-prefix",
                       "--skip-columns=guid", "--format=count"], sr)
        self.assertIn(self.sites[self.STAGING]["docroot"], [a[1] for a in sr])
        self.assertIn((self.LIVE, ["option", "update", "blog_public", "1"]), self.wp_calls)
        self.assertEqual(r["kept_tables"], ["wp_wc_orders"])
        self.assertGreater(r["replacements"], 0)
        # No option file with a password stays behind.
        self.assertEqual([f for f in os.listdir(os.path.join(paths.STATE_DIR, "clone")) if f.endswith((".cnf", ".sql"))], [])

    def test_files_only_push_leaves_database_alone(self):
        state = wait(st.staging_push({"staging": self.STAGING, "live": self.LIVE, "database": False}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual(self.imports, [])
        self.assertFalse([a for _, a in self.wp_calls if a[0] == "search-replace"])

    def test_broken_site_after_push_is_put_back(self):
        self.health = [200, 500]
        state = wait(st.staging_push({"staging": self.STAGING, "live": self.LIVE}))
        self.assertEqual(state["status"], "failed")
        self.assertIn("HTTP 500", state["error"]["message"])
        self.assertTrue(state["error"]["details"]["rolled_back"])
        self.assertEqual((self.live_doc / "index.php").read_text(), "live v1")
        self.assertTrue((self.live_doc / "only-live.txt").exists())
        self.assertFalse((self.live_doc / "wp-content/uploads/new-photo.jpg").exists())
        # The backup was loaded again and the table the push added is dropped.
        self.assertEqual(len(self.imports), 2)
        drops = [c for c in self.calls if c[0] == "sql"]
        self.assertIn("DROP TABLE IF EXISTS `wp_newplugin`", drops[-1][2])

    def test_failed_import_is_put_back(self):
        self.fail_import = True
        state = wait(st.staging_push({"staging": self.STAGING, "live": self.LIVE}))
        self.assertEqual(state["status"], "failed")
        self.assertIn("could not be loaded", state["error"]["message"])
        self.assertEqual((self.live_doc / "index.php").read_text(), "live v1")

    def test_restore_and_prune(self):
        ids = []
        for _ in range(st.KEEP_BACKUPS + 1):
            with mock.patch.object(st.time, "strftime", return_value=f"20261107-0100{len(ids):02d}"):
                state = wait(st.staging_push({"staging": self.STAGING, "live": self.LIVE, "database": False}))
            self.assertEqual(state["status"], "succeeded", state)
            ids.append(state["result"]["backup"])
        listed = [b["id"] for b in st.staging_backups({"live": self.LIVE})["backups"]]
        self.assertEqual(listed, list(reversed(ids))[:st.KEEP_BACKUPS])
        state = wait(st.staging_restore({"live": self.LIVE, "backup": ids[0] if ids[0] in listed else listed[-1]}))
        self.assertEqual(state["status"], "succeeded", state)
        with self.assertRaises(ActionError):
            st.staging_restore({"live": self.LIVE, "backup": "../../etc"})
        with self.assertRaises(ActionError):
            st.staging_restore({"live": self.LIVE, "backup": "20200101-000000"})

    def test_prepare_adds_marker(self):
        def as_user(site, argv, cwd, **kw):
            if argv[0].endswith("mkdir"):
                os.makedirs(argv[-1], exist_ok=True)
            if argv[0].endswith("touch"):
                Path(argv[-1]).touch()
            return CompletedProcess(argv, 0, "", "")

        shutil.rmtree(self.stag_doc / "wp-content/mu-plugins")
        with mock.patch.object(st.wordpress, "as_user", side_effect=as_user):
            out = st.staging_prepare({"staging": self.STAGING})
        self.assertEqual(out["mu_plugin"], st.MU_PLUGIN)
        code = (self.stag_doc / st.MU_PLUGIN).read_text()
        self.assertIn("pre_wp_mail", code)
        self.assertIn((self.STAGING, ["option", "update", "blog_public", "0"]), self.wp_calls)


if __name__ == "__main__":
    unittest.main()
