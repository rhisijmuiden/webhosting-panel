"""wphard.*: wp-config edits, Nginx snippet, mu-plugin and the apply/undo flow,
with files in a temp folder owned by the test user and nginx mocked out.

The real Nginx behaviour is checked in the Debian 13 smoke test (tests/smoke.d/wp-hardening.sh).
"""

import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import web_rules  # noqa: E402
from whp_agent.actions import wp_hardening as h  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

CONFIG = """<?php
define( 'DB_NAME', 'db' );
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'a' );
define( 'LOGGED_IN_KEY',    'b' );
define( 'NONCE_KEY',        'c' );
define( 'AUTH_SALT',        'd' );
define( 'SECURE_AUTH_SALT', 'e' );
define( 'LOGGED_IN_SALT',   'f' );
define( 'NONCE_SALT',       'g' );
$table_prefix = 'wp_';

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
"""


def read(path):
    with open(path) as fh:
        return fh.read()


class TextTest(unittest.TestCase):
    def test_file_edit_added_above_stop_editing_and_removed_again(self):
        on = h.set_file_edit(CONFIG, True)
        self.assertTrue(h.config_bool(on, "DISALLOW_FILE_EDIT"))
        self.assertLess(on.index("DISALLOW_FILE_EDIT"), on.index("That's all"))
        self.assertEqual(h.set_file_edit(on, True).count("DISALLOW_FILE_EDIT"), 1)
        self.assertEqual(h.set_file_edit(on, False), CONFIG)

    def test_file_edit_replaces_existing_false(self):
        text = CONFIG.replace("$table_prefix", "define('DISALLOW_FILE_EDIT', false);\n$table_prefix")
        self.assertFalse(h.config_bool(text, "DISALLOW_FILE_EDIT"))
        on = h.set_file_edit(text, True)
        self.assertTrue(h.config_bool(on, "DISALLOW_FILE_EDIT"))
        self.assertEqual(on.count("DISALLOW_FILE_EDIT"), 1)

    def test_unusual_config_is_refused(self):
        with self.assertRaises(ActionError):
            h.set_file_edit("<?php\ninclude 'x.php';\n", True)

    def test_salts_replaced_and_missing_ones_added(self):
        text = CONFIG.replace("define( 'NONCE_SALT',       'g' );\n", "")
        values = {k: h.new_salt() for k in h.SALT_KEYS}
        new = h.set_salts(text, values)
        self.assertEqual(h.read_salts(new), values)
        self.assertNotIn("put your unique phrase here", new)
        for v in values.values():
            self.assertEqual(len(v), 64)
            self.assertNotIn("'", v)
            self.assertNotIn("\\", v)

    def test_nginx_rules_per_install(self):
        text = h.render_nginx({"": ["php_folders", "user_enum"], "blog": ["wp_config", "hide_version"]})
        self.assertIn('location ~* "^/wp-includes/[^/]+\\.php$" { deny all; return 404; }', text)
        self.assertIn('location ~* "^/blog/wp-config(?:-sample)?\\.php$"', text)
        self.assertIn('location ~* "^/blog/(?:readme\\.html|license\\.txt)$"', text)
        self.assertIn('if ($whp_wph_root = "au") { return 403; }', text)
        self.assertIn('if ($uri ~ "^/wp-admin/") { set $whp_wph_root "${whp_wph_root}x"; }', text)
        # The folder install comes before the root one.
        self.assertLess(text.index("/blog"), text.index("document root"))
        self.assertNotIn("/blog/wp-includes", text)

    def test_mu_plugin_contains_only_enabled_parts(self):
        self.assertIsNone(h.render_mu(set()))
        text = h.render_mu({"user_enum"})
        self.assertEqual(h.mu_measures(text), ["user_enum"])
        self.assertIn("rest_endpoints", text)
        self.assertNotIn("wp_generator", text)
        self.assertEqual(h.mu_measures(h.render_mu({"user_enum", "hide_version"})), ["hide_version", "user_enum"])


def _local_as_user(site, argv, cwd, input_text=None, timeout=300, check=True):
    return subprocess.run(argv, cwd=cwd, capture_output=True, text=True, check=check)


class FlowTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = self.tmp.name
        self.docroot = os.path.join(root, "www", "public")
        os.makedirs(os.path.join(self.docroot, "wp-includes"))
        os.makedirs(os.path.join(self.docroot, "wp-content"))
        with open(os.path.join(self.docroot, "wp-includes", "version.php"), "w") as fh:
            fh.write("<?php\n$wp_version = '6.8.1';\n")
        self.cfg = os.path.join(self.docroot, "wp-config.php")
        with open(self.cfg, "w") as fh:
            fh.write(CONFIG)
        os.chmod(self.cfg, 0o644)
        self.site = {"domain": "a.nl", "user": "s_a", "docroot": self.docroot, "root": os.path.join(root, "www")}
        patches = [
            mock.patch.object(h, "STATE", os.path.join(root, "state")),
            mock.patch.object(web_rules, "NGINX_WHP", os.path.join(root, "nginx")),
            mock.patch.object(wordpress, "site_info", return_value=self.site),
            mock.patch.object(h, "_site_ids", return_value=(os.getuid(), os.getgid())),
            mock.patch.object(wordpress, "as_user", side_effect=_local_as_user),
            mock.patch.object(web_rules, "reload_nginx"),
        ]
        for p in patches:
            p.start()
        self.addCleanup(mock.patch.stopall)
        self.nginx_test = mock.patch.object(web_rules, "_nginx_test").start()

    def call(self, name, **params):
        return ACTIONS[name]({"domain": "a.nl", **params})

    def snippet(self):
        p = h.snippet_path("a.nl")
        return read(p) if os.path.exists(p) else None

    def test_registered(self):
        for name in ("wphard.status", "wphard.apply", "wphard.salts"):
            self.assertIn(name, ACTIONS)

    def test_status_of_a_fresh_install(self):
        s = self.call("wphard.status")
        self.assertEqual(s["on"], 0)
        self.assertEqual(s["total"], 5)
        self.assertEqual(s["measures"]["wp_config"]["mode"], "0644")
        self.assertTrue(s["measures"]["php_folders"]["uploads_by_server"])
        self.assertTrue(s["salts"]["default"])
        self.assertTrue(s["server"]["directory_listing"])

    def test_everything_on_and_off_again(self):
        all_on = {m: True for m in h.MEASURES}
        s = self.call("wphard.apply", measures=all_on)
        self.assertEqual(s["on"], 5, s)
        self.assertEqual(sorted(s["changed"]), sorted(h.MEASURES))
        self.assertEqual(stat.S_IMODE(os.stat(self.cfg).st_mode), 0o600)
        self.assertTrue(h.config_bool(read(self.cfg), "DISALLOW_FILE_EDIT"))
        mu = os.path.join(self.docroot, "wp-content", "mu-plugins", "whp-hardening.php")
        self.assertEqual(h.mu_measures(read(mu)), ["hide_version", "user_enum"])
        self.assertIn("wp-admin/includes", self.snippet())
        self.nginx_test.assert_called()

        # Again: nothing to change.
        self.assertEqual(self.call("wphard.apply", measures=all_on)["changed"], [])

        s = self.call("wphard.apply", measures={m: False for m in h.MEASURES})
        self.assertEqual(s["on"], 0)
        self.assertEqual(stat.S_IMODE(os.stat(self.cfg).st_mode), 0o644)
        self.assertEqual(read(self.cfg), CONFIG)
        self.assertFalse(os.path.exists(mu))
        self.assertIsNone(self.snippet())

    def test_single_measure_keeps_the_others(self):
        self.call("wphard.apply", measures={"hide_version": True, "user_enum": True})
        s = self.call("wphard.apply", measures={"hide_version": False})
        self.assertFalse(s["measures"]["hide_version"]["on"])
        self.assertTrue(s["measures"]["user_enum"]["on"])
        self.assertNotIn("readme", self.snippet())

    def test_nginx_refusal_puts_old_snippet_back(self):
        self.call("wphard.apply", measures={"php_folders": True})
        before = self.snippet()
        self.nginx_test.side_effect = ActionError("conflict", "nope")
        with self.assertRaises(ActionError):
            self.call("wphard.apply", measures={"user_enum": True})
        self.assertEqual(self.snippet(), before)

    def test_customer_chmod_shows_up_in_status(self):
        self.call("wphard.apply", measures={"wp_config": True})
        os.chmod(self.cfg, 0o644)
        self.assertFalse(self.call("wphard.status")["measures"]["wp_config"]["on"])

    def test_symlinked_config_is_refused(self):
        os.rename(self.cfg, self.cfg + ".real")
        os.symlink(self.cfg + ".real", self.cfg)
        with self.assertRaises(ActionError):
            self.call("wphard.apply", measures={"file_edit": True})

    def test_unknown_measure_refused(self):
        with self.assertRaises(ActionError):
            self.call("wphard.apply", measures={"rm_rf": True})
        with self.assertRaises(ActionError):
            self.call("wphard.apply", measures={"file_edit": "yes"})

    def test_salts_refresh_and_undo(self):
        old = h.read_salts(CONFIG)
        s = self.call("wphard.salts")
        new = h.read_salts(read(self.cfg))
        self.assertTrue(all(new[k] != old[k] for k in h.SALT_KEYS))
        self.assertTrue(s["salts"]["undo"])
        self.assertIsNotNone(s["salts"]["refreshed_at"])
        backup = h._salts_file("a.nl", "")
        self.assertEqual(stat.S_IMODE(os.stat(backup).st_mode), 0o600)
        self.assertEqual(json.loads(read(backup))["salts"], old)

        s = self.call("wphard.salts", undo=True)
        self.assertEqual(h.read_salts(read(self.cfg)), old)
        self.assertFalse(s["salts"]["undo"])
        with self.assertRaises(ActionError):
            self.call("wphard.salts", undo=True)


if __name__ == "__main__":
    unittest.main()
