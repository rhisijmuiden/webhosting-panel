"""Migration: WordPress config and path fixes after the copy (no WP-CLI or MariaDB needed)."""

import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent.actions import migrate  # noqa: E402
from whp_agent.migration import wordpress_fix as fix  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

OLD = "/var/www/vhosts/schaduw.test/httpdocs"
NEW = "/var/www/vhosts/schaduw.test/public"
CONFIG = """<?php
define( 'DB_NAME', 'wp_schaduw' );
define( 'DB_USER', 'wp_user' );
define( 'DB_PASSWORD', 'oud\\'wacht' );
define("DB_HOST", "localhost:3306");
define('WP_CACHE', true);
define('WPCACHEHOME', '/var/www/vhosts/schaduw.test/httpdocs/wp-content/plugins/wp-super-cache/');
$x = '/var/www/vhosts/schaduw.test/httpdocs2/other';
"""


class ConfigTest(unittest.TestCase):
    def test_host_password_and_paths(self):
        text, changed = fix.fix_config(CONFIG, OLD, NEW, {"wp_user": "n3w'pw\\x"}, set())
        self.assertEqual(changed, ["DB_HOST", "DB_PASSWORD", "paths"])
        self.assertEqual(fix.config_value(text, "DB_HOST"), "localhost")
        self.assertEqual(fix.config_value(text, "DB_PASSWORD"), "n3w'pw\\x")
        self.assertIn(f"'{NEW}/wp-content/plugins/wp-super-cache/'", text)
        self.assertIn("httpdocs2/other", text, "only whole path segments are replaced")
        self.assertEqual(fix.config_value(text, "DB_NAME"), "wp_schaduw")

    def test_old_password_kept_and_remote_host_untouched(self):
        text = CONFIG.replace("localhost:3306", "db.elders.test")
        new, changed = fix.fix_config(text, OLD, NEW, {}, {"plesk.test"})
        self.assertEqual(changed, ["paths"])
        self.assertEqual(fix.config_value(new, "DB_HOST"), "db.elders.test")
        self.assertEqual(fix.config_value(new, "DB_PASSWORD"), "oud'wacht")

    def test_source_server_name_counts_as_local(self):
        new, changed = fix.fix_config(CONFIG.replace("localhost:3306", "plesk.test"), OLD, NEW, {}, {"plesk.test"})
        self.assertIn("DB_HOST", changed)

    def test_idempotent(self):
        once, _ = fix.fix_config(CONFIG, OLD, NEW, {"wp_user": "x"}, set())
        twice, changed = fix.fix_config(once, OLD, NEW, {"wp_user": "x"}, set())
        self.assertEqual(once, twice)
        self.assertEqual(changed, [])


class SiteTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.docroot = os.path.join(self.tmp.name, "public")
        os.makedirs(os.path.join(self.docroot, "wp-content", "wflogs"))
        self.uid, self.gid = os.getuid(), os.getgid()
        self.write("wp-config.php", CONFIG)
        self.write(".user.ini", f"auto_prepend_file = '{OLD}/wordfence-waf.php'\n")
        self.write("wp-content/wflogs/config.php", f"<?php $p = '{OLD}/wp-content';")
        self.write(".htaccess", "RewriteEngine On\n")

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, rel, text):
        with open(os.path.join(self.docroot, rel), "w") as fh:
            fh.write(text)

    def read(self, rel):
        with open(os.path.join(self.docroot, rel)) as fh:
            return fh.read()

    def run_fix(self, wp_proc):
        site = {"user": "s_1", "docroot": self.docroot}
        calls = []

        def wp(domain, args, path="", **kw):
            calls.append(args)
            return wp_proc

        with mock.patch.object(fix.wordpress, "site_info", return_value=site), \
                mock.patch.object(fix.wordpress, "detect", return_value=[{"path": "", "dir": self.docroot, "version": "6.6"}]), \
                mock.patch.object(fix.wordpress, "wp", side_effect=wp), \
                mock.patch("pwd.getpwnam", return_value=types.SimpleNamespace(pw_uid=self.uid, pw_gid=self.gid)):
            out = fix.fix_site("schaduw.test", OLD, {"wp_user": "nieuw"}, set(), lambda line: None)
        return out, calls

    def test_files_and_database_are_fixed(self):
        os.chmod(os.path.join(self.docroot, "wp-config.php"), 0o640)
        out, calls = self.run_fix(types.SimpleNamespace(returncode=0, stdout="12\n", stderr=""))
        self.assertTrue(out[0]["ok"])
        self.assertEqual(out[0]["db_replacements"], 12)
        self.assertEqual(sorted(out[0]["files"]), [".user.ini", "wp-config.php", "wp-content/wflogs/config.php"])
        self.assertEqual(out[0]["config"], ["DB_HOST", "DB_PASSWORD", "paths"])
        self.assertIn(f"'{os.path.realpath(self.docroot)}/wordfence-waf.php'", self.read(".user.ini"))
        self.assertEqual(os.stat(os.path.join(self.docroot, "wp-config.php")).st_mode & 0o777, 0o640)
        self.assertEqual(calls[0][:3], ["search-replace", OLD, os.path.realpath(self.docroot)])
        self.assertIn("--skip-columns=guid", calls[0])

    def test_symlinked_config_is_left_alone(self):
        target = os.path.join(self.tmp.name, "secret")
        with open(target, "w") as fh:
            fh.write(CONFIG)
        os.remove(os.path.join(self.docroot, "wp-config.php"))
        os.symlink(target, os.path.join(self.docroot, "wp-config.php"))
        out, _ = self.run_fix(types.SimpleNamespace(returncode=0, stdout="0", stderr=""))
        with open(target) as fh:
            self.assertEqual(fh.read(), CONFIG)
        self.assertNotIn("wp-config.php", out[0]["files"])

    def test_wp_cli_failure_is_reported(self):
        out, _ = self.run_fix(types.SimpleNamespace(returncode=1, stdout="", stderr="Error: Error establishing a database connection."))
        self.assertFalse(out[0]["ok"])
        self.assertIn("database connection", out[0]["error"])


class ParamsTest(unittest.TestCase):
    def test_db_passwords(self):
        plan = migrate.parse_transfer({"domain": "a.test", "db_passwords": {"wp_user": "x"}})
        self.assertEqual(plan["db_passwords"], {"wp_user": "x"})
        self.assertTrue(plan["wordpress"])
        with self.assertRaises(ActionError):
            migrate.parse_transfer({"domain": "a.test", "db_passwords": {"bad user;": "x"}})


class ActionTest(unittest.TestCase):
    def test_wordpress_fix_action_checks_the_old_root(self):
        for bad in ("/etc/passwd", "/var/www/vhosts/a.test/../../etc", "/var/www/vhosts/a.test"):
            with self.assertRaises(ActionError):
                migrate.migrate_wordpress_fix({"domain": "a.test", "old_root": bad})
        with mock.patch.object(migrate.wordpress_fix, "fix_site", return_value=[{"path": "", "ok": True}]) as fix_site:
            out = migrate.migrate_wordpress_fix({"domain": "a.test", "old_root": "/var/www/vhosts/a.test/httpdocs",
                                                 "db_passwords": {"wp": "x"}, "source_host": "plesk.test"})
        self.assertEqual(out["wordpress"], [{"path": "", "ok": True}])
        self.assertEqual(fix_site.call_args.args[:4], ("a.test", "/var/www/vhosts/a.test/httpdocs", {"wp": "x"}, {"plesk.test"}))


if __name__ == "__main__":
    unittest.main()
