"""site.clone: the pure helpers and the job with rsync, MariaDB and WP-CLI mocked out.

The real copy (rsync, mariadb-dump, WP-CLI search-replace) runs in the Debian 13
smoke test (tests/clone_smoke.py).
"""

import os
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
from whp_agent.actions import site_clone as clone  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

WP_CONFIG = """<?php
define( 'DB_NAME', 'prod_nl_wp_1a2b' );
define( 'DB_USER', 'prod_nl_wp_1a2b' );
define( 'DB_PASSWORD', 'geheim"\\'pw' );
define( 'DB_HOST', 'localhost' );
define( 'WP_HOME', 'https://www.prod.nl' );
define( 'COOKIE_DOMAIN', 'www.prod.nl' );
define( 'WPCACHEHOME', '/var/www/vhosts/prod.nl/public/wp-content/plugins/wp-super-cache/' );
$table_prefix = 'wp_';
"""

JOOMLA = """<?php
class JConfig {
	public $host = 'localhost';
	public $user = 'jm_user';
	public $password = 'o\\'ud';
	public $db = 'jm_db';
	public $dbprefix = 'jos_';
	public $live_site = 'https://prod.nl';
	public $secret = 'oldsecret';
	public $log_path = '/var/www/vhosts/prod.nl/public/administrator/logs';
	public $tmp_path = '/var/www/vhosts/prod.nl/public/tmp';
	public $cookie_domain = '';
}
"""


class HelperTest(unittest.TestCase):
    def test_definers_removed_from_ddl_only(self):
        ddl = "/*!50003 CREATE*/ /*!50017 DEFINER=`prod`@`localhost`*/ /*!50003 TRIGGER t BEFORE INSERT ON x */;\n"
        self.assertNotIn("DEFINER", clone.strip_definers(ddl))
        view = "/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\n"
        self.assertEqual(clone.strip_definers(view), "/*!50013 SQL SECURITY DEFINER */\n")
        data = "INSERT INTO `p` VALUES (1,'DEFINER=`a`@`b`');\n"
        self.assertEqual(clone.strip_definers(data), data)

    def test_mycnf_quotes_and_refuses_newlines(self):
        text = clone.mycnf('u', 'p"w\\x', "db")
        self.assertIn('password="p\\"w\\\\x"', text)
        self.assertIn('database="db"', text)
        with self.assertRaises(ActionError):
            clone.mycnf("u", "a\nb")

    def test_local_hosts(self):
        self.assertTrue(clone.is_local_db("localhost"))
        self.assertTrue(clone.is_local_db("127.0.0.1:3306"))
        self.assertFalse(clone.is_local_db("db.example.com"))

    def test_url_pairs_longest_first_and_escaped(self):
        pairs = clone.url_pairs(["prod.nl", "www.prod.nl"], "https://test.prod.nl")
        self.assertEqual(pairs[0], ("https://www.prod.nl", "https://test.prod.nl"))
        self.assertIn(("http://prod.nl", "https://test.prod.nl"), pairs)
        self.assertIn(("https:\\/\\/prod.nl", "https:\\/\\/test.prod.nl"), pairs)
        self.assertEqual(clone.url_pairs(["test.prod.nl"], "https://test.prod.nl"), [])

    def test_replace_hosts_whole_names_only(self):
        pairs = clone.url_pairs(["prod.nl"], "https://test.prod.nl")
        text = "a https://prod.nl/x b https://prod.nl.be c http://prod.nl. d https://prodx.nl"
        self.assertEqual(clone.replace_hosts(text, pairs),
                         "a https://test.prod.nl/x b https://prod.nl.be c https://test.prod.nl. d https://prodx.nl")

    def test_wp_config_rewrite(self):
        db = {"name": "test_db", "user": "test_user", "password": "n'w"}
        pairs = clone.url_pairs(["prod.nl", "www.prod.nl"], "https://test.prod.nl")
        out = clone.fix_wp_config(WP_CONFIG, db, "/var/www/vhosts/prod.nl/public", "/var/www/vhosts/test.prod.nl/public", pairs, "test.prod.nl")
        from whp_agent.migration import wordpress_fix as fix
        self.assertEqual(fix.config_value(out, "DB_NAME"), "test_db")
        self.assertEqual(fix.config_value(out, "DB_PASSWORD"), "n'w")
        self.assertEqual(fix.config_value(out, "WP_HOME"), "https://test.prod.nl")
        self.assertEqual(fix.config_value(out, "COOKIE_DOMAIN"), "test.prod.nl")
        self.assertIn("/var/www/vhosts/test.prod.nl/public/wp-content/plugins", out)

    def test_joomla_rewrite(self):
        db = {"name": "new_db", "user": "new_user", "password": "p'w"}
        out = clone.fix_joomla_config(JOOMLA, db, "/var/www/vhosts/prod.nl/public", "/var/www/vhosts/test.prod.nl/public",
                                      "https://test.prod.nl", "test.prod.nl")
        self.assertEqual(clone.joomla_value(out, "db"), "new_db")
        self.assertEqual(clone.joomla_value(out, "password"), "p'w")
        self.assertEqual(clone.joomla_value(out, "live_site"), "https://test.prod.nl")
        self.assertEqual(clone.joomla_value(out, "cookie_domain"), "")
        self.assertNotEqual(clone.joomla_value(out, "secret"), "oldsecret")
        self.assertEqual(clone.joomla_value(out, "tmp_path"), "/var/www/vhosts/test.prod.nl/public/tmp")


def wait(job):
    for _ in range(200):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


class CloneJobTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.vhosts = base / "vhosts"
        self.sites = {}
        for domain in ("prod.nl", "test.prod.nl"):
            root = self.vhosts / domain
            (root / "public").mkdir(parents=True)
            (root / "tmp").mkdir()
            self.sites[domain] = {"domain": domain, "id": domain.replace(".", "_"), "user": "s_" + domain.replace(".", "_"),
                                  "php": "8.4", "root": str(root), "docroot": str(root / "public"),
                                  "ssl": "active", "aliases": ["www.prod.nl"] if domain == "prod.nl" else [],
                                  "suspended": False, "slice": "whp-sites.slice"}
        prod = Path(self.sites["prod.nl"]["docroot"])
        (prod / "wp-config.php").write_text(WP_CONFIG)
        (prod / "wp-includes").mkdir()
        (prod / "wp-includes" / "version.php").write_text("<?php\n$wp_version = '6.8.1';\n")
        (Path(self.sites["test.prod.nl"]["docroot"]) / "index.html").write_text(
            '<!doctype html><meta charset="utf-8"><title>test.prod.nl</title><p>test.prod.nl</p>\n')
        state = base / "state"
        self.patches = [
            mock.patch.object(paths, "STATE_DIR", str(state)),
            mock.patch.object(paths, "JOBS_DIR", str(state / "jobs")),
            mock.patch.object(clone.wordpress, "site_info", side_effect=lambda d: dict(self.sites[d])),
            mock.patch.object(clone, "_uid", return_value=(os.getuid(), os.getgid())),
            mock.patch.object(clone, "_du_mb", return_value=12),
            mock.patch("whp_agent.actions.apps._pwnam", side_effect=lambda u: __import__("pwd").getpwuid(os.getuid())),
        ]
        for p in self.patches:
            p.start()
        self.calls = []

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def fake_run(self, argv, **kw):
        self.calls.append(argv)
        if argv[0] == "rsync":
            src, dst = argv[-2], argv[-1]
            import shutil
            shutil.copytree(src, dst, dirs_exist_ok=True)
        if argv[0] == clone.MARIADB_DUMP:
            out = [a for a in argv if a.startswith("--result-file=")][0].split("=", 1)[1]
            Path(out).write_text("/*!50017 DEFINER=`x`@`localhost`*/ CREATE TRIGGER;\nINSERT INTO t VALUES (1);\n")
        return CompletedProcess(argv, 0, "", "")

    def test_plan_lists_wordpress_and_database(self):
        with mock.patch.object(clone.wordpress, "detect", return_value=[
                {"path": "", "dir": self.sites["prod.nl"]["docroot"], "version": "6.8.1"}]):
            plan = clone.clone_plan({"source": "prod.nl", "target": "test.prod.nl"})
        self.assertEqual(plan["installs"][0]["kind"], "wordpress")
        self.assertEqual(plan["installs"][0]["db_name"], "prod_nl_wp_1a2b")
        self.assertNotIn("_password", plan["installs"][0])
        self.assertEqual(plan["target_content"], [])

    def test_same_site_refused(self):
        with self.assertRaises(ActionError):
            clone.clone_plan({"source": "prod.nl", "target": "prod.nl"})

    def test_target_with_content_needs_replace(self):
        Path(self.sites["test.prod.nl"]["docroot"], "index.php").write_text("<?php")
        with self.assertRaises(ActionError) as cm:
            clone.clone_start({"source": "prod.nl", "target": "test.prod.nl"})
        self.assertEqual(cm.exception.code, "conflict")

    def test_remote_database_blocks_clone(self):
        Path(self.sites["prod.nl"]["docroot"], "wp-config.php").write_text(WP_CONFIG.replace("'localhost'", "'db.elders.nl'"))
        with mock.patch.object(clone.wordpress, "detect", return_value=[
                {"path": "", "dir": self.sites["prod.nl"]["docroot"], "version": "6.8.1"}]):
            plan = clone.clone_plan({"source": "prod.nl", "target": "test.prod.nl"})
        self.assertIn("another server", plan["installs"][0]["problem"])

    def test_full_clone(self):
        wp_calls = []

        def fake_wp(domain, args, path="", **kw):
            wp_calls.append((domain, args))
            return CompletedProcess(args, 0, "3\n", "")

        imported = {}

        class Res:
            returncode = 0
            stderr = b""

        def fake_subprocess_run(argv, stdin=None, **kw):
            imported["sql"] = stdin.read().decode()
            imported["argv"] = argv
            return Res()

        with mock.patch.object(clone, "run", side_effect=self.fake_run), \
                mock.patch.object(clone.wordpress, "detect", side_effect=lambda d: [
                    {"path": "", "dir": self.sites[d]["docroot"], "version": "6.8.1"}]), \
                mock.patch.object(clone.wordpress, "wp", side_effect=fake_wp), \
                mock.patch.object(clone.subprocess, "run", side_effect=fake_subprocess_run), \
                mock.patch.object(clone.databases, "sql", return_value=""):
            state = wait(clone.clone_start({"source": "prod.nl", "target": "test.prod.nl"}))
        self.assertEqual(state["status"], "succeeded", state)
        result = state["result"]
        self.assertEqual(result["installs"][0]["kind"], "wordpress")
        self.assertEqual(result["installs"][0]["url"], "https://test.prod.nl")
        self.assertTrue(result["databases"][0].startswith("test_prod_nl_clone_"))
        # Dump with the site's own credentials, import as the new user, definers gone.
        dump = [c for c in self.calls if c[0] == clone.MARIADB_DUMP][0]
        self.assertTrue(dump[1].startswith("--defaults-extra-file="))
        self.assertNotIn("-uroot", dump)
        self.assertNotIn("DEFINER", imported["sql"])
        self.assertTrue(imported["argv"][1].startswith("--defaults-extra-file="))
        # Option files with passwords are gone afterwards.
        self.assertEqual([f for f in os.listdir(os.path.join(paths.STATE_DIR, "clone")) if f.endswith(".cnf")], [])
        cfg = Path(self.sites["test.prod.nl"]["docroot"], "wp-config.php").read_text()
        self.assertIn(result["databases"][0], cfg)
        self.assertIn("https://test.prod.nl", cfg)
        self.assertFalse(Path(self.sites["test.prod.nl"]["docroot"], "index.html").exists(), "placeholder replaced")
        sr = [a for _, a in wp_calls if a[0] == "search-replace"]
        self.assertIn(["search-replace", "https://www.prod.nl", "https://test.prod.nl", "--all-tables-with-prefix",
                       "--skip-columns=guid", "--format=count"], sr)
        self.assertIn(self.sites["prod.nl"]["docroot"], [a[1] for a in sr])
        self.assertIn(["option", "update", "blog_public", "0"], [a for _, a in wp_calls])
        self.assertIn(["config", "shuffle-salts"], [a for _, a in wp_calls])
        rsync = [c for c in self.calls if c[0] == "rsync"][0]
        self.assertIn("--safe-links", rsync)
        self.assertIn("--chown=s_test_prod_nl:s_test_prod_nl", rsync)

    def test_failed_import_rolls_back(self):
        class Res:
            returncode = 1
            stderr = b"ERROR 1227: Access denied"

        dropped = []
        with mock.patch.object(clone, "run", side_effect=self.fake_run), \
                mock.patch.object(clone.wordpress, "detect", side_effect=lambda d: [
                    {"path": "", "dir": self.sites[d]["docroot"], "version": "6.8.1"}]), \
                mock.patch.object(clone.subprocess, "run", return_value=Res()), \
                mock.patch.object(clone.databases, "sql", return_value=""), \
                mock.patch.object(clone.databases, "drop", side_effect=lambda n, d: dropped.append(n)):
            state = wait(clone.clone_start({"source": "prod.nl", "target": "test.prod.nl"}))
        self.assertEqual(state["status"], "failed")
        self.assertIn("Access denied", state["error"]["message"])
        self.assertEqual(len(dropped), 1)
        target = Path(self.sites["test.prod.nl"]["docroot"])
        self.assertEqual(sorted(os.listdir(target)), ["index.html"], "target emptied, placeholder back")


if __name__ == "__main__":
    unittest.main()
