"""WordPress quick switches: what the agent asks WP-CLI to do, and the debug log
reader. WP-CLI itself is faked."""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import wp_switches as sw  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def make_wp(d):
    (d / "wp-includes").mkdir(parents=True)
    (d / "wp-content/mu-plugins").mkdir(parents=True)
    (d / "wp-config.php").write_text("<?php\n")
    (d / "wp-includes/version.php").write_text("<?php\n$wp_version = '6.7.1';\n")


class FakeRun:
    def __init__(self):
        self.calls = []
        self.state = {"debug": False, "debug_log": False, "debug_display": True, "blog_public": "1", "maintenance": None}
        self.fail = {}

    def __call__(self, argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
        argv = list(argv)
        self.calls.append({"argv": argv, "input": input_text})
        out, code = "", 0
        if wordpress.WP_CLI in argv:
            args = argv[argv.index(wordpress.WP_CLI) + 3:]
            key = " ".join(args[:2])
            if key in self.fail:
                return SimpleNamespace(stdout="", stderr=self.fail[key], returncode=1)
            if args[0] == "eval" and "get_option('whp_maintenance')" in args[1]:
                out = "PHP Notice: something\n" + json.dumps(self.state)
            elif args[0] == "eval":
                out = json.dumps({"plugins": ["WP Rocket"]})
        return SimpleNamespace(stdout=out, stderr="", returncode=code)

    def wp_args(self):
        return [c["argv"][c["argv"].index(wordpress.WP_CLI) + 3:] for c in self.calls if wordpress.WP_CLI in c["argv"]]

    def user_cmds(self):
        """Commands run as the site user that are not WP-CLI."""
        out = []
        for c in self.calls:
            a = c["argv"]
            if a[0] == wordpress.SYSTEMD_RUN and wordpress.WP_CLI not in a:
                i = max(n for n, x in enumerate(a) if x.startswith("--setenv="))
                out.append((a[i + 1:], c["input"]))
        return out


class SwitchesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        (root / "sites").mkdir()
        (root / "sites/klant.nl.env").write_text(
            "SITE_DOMAIN=klant.nl\nSITE_ID=klant_nl\nSITE_USER=s_klant_nl\nSITE_PHP=8.4\nSITE_DOCROOT_REL=public\n")
        self.site_root = root / "vhosts/klant.nl"
        self.docroot = self.site_root / "public"
        make_wp(self.docroot)
        make_wp(self.docroot / "shop")
        (self.site_root / "logs").mkdir()
        self.fake = FakeRun()
        self.patches = [
            mock.patch.object(wordpress, "SITES_DB", str(root / "sites")),
            mock.patch.dict(os.environ, {"WHP_VHOSTS": str(root / "vhosts")}),
            mock.patch.object(wordpress, "run", self.fake),
            mock.patch.object(wordpress, "ensure_cli", lambda: wordpress.WP_CLI),
            mock.patch.object(sw, "run", self.fake),
        ]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in reversed(self.patches):
            p.stop()
        self.tmp.cleanup()

    # --- state

    def test_state_reads_everything_in_one_wp_cli_call(self):
        self.fake.state.update(debug=True, debug_log=str(self.site_root / "logs/wp-debug.log"), blog_public="0")
        (self.docroot / "wp-content/debug.log").write_text("old\n")
        st = sw.wp_switches({"domain": "klant.nl"})
        self.assertEqual(1, len(self.fake.wp_args()))
        self.assertIn("--skip-plugins", self.fake.wp_args()[0])
        self.assertTrue(st["debug"])
        self.assertEqual("panel", st["debug_log"])
        self.assertFalse(st["indexing"])
        self.assertFalse(st["maintenance"])
        self.assertEqual({"size": 4, "modified": st["public_log"]["modified"]}, st["public_log"])

    def test_wordpress_default_debug_log_is_reported_as_public(self):
        self.fake.state.update(debug=True, debug_log=True)
        self.assertEqual("public", sw.wp_switches({"domain": "klant.nl"})["debug_log"])

    def test_log_names_per_install(self):
        self.assertEqual("wp-debug.log", sw.log_name(""))
        self.assertEqual("wp-debug-shop-nl.log", sw.log_name("shop/nl"))

    # --- maintenance

    def test_maintenance_on_writes_option_and_mu_plugin_as_site_user_then_purges(self):
        sw.wp_maintenance_set({"domain": "klant.nl", "enabled": True, "title": "Even geduld",
                               "message": "We zijn zo terug.\nTot straks!", "lang": "nl"})
        opt = next(a for a in self.fake.wp_args() if a[:2] == ["option", "update"])
        self.assertEqual("whp_maintenance", opt[2])
        self.assertEqual({"title": "Even geduld", "message": "We zijn zo terug.\nTot straks!", "lang": "nl", "retry_after": 3600},
                         json.loads(opt[3]))
        (cmd, stdin), = self.fake.user_cmds()
        self.assertEqual("/bin/sh", cmd[0])
        self.assertIn("wp-content/mu-plugins/whp-maintenance.php", cmd[2])
        self.assertIn("status_header(503)", stdin)
        self.assertTrue(any(c["argv"][:4] == [sw.WHP_SITE, "cache", "klant.nl", "purge"] for c in self.fake.calls))

    def test_maintenance_off_removes_the_mu_plugin(self):
        sw.wp_maintenance_set({"domain": "klant.nl", "enabled": False})
        (cmd, _), = self.fake.user_cmds()
        self.assertEqual(["/usr/bin/rm", "-f", "--", "wp-content/mu-plugins/whp-maintenance.php"], cmd)

    def test_maintenance_state_follows_the_file(self):
        (self.docroot / "wp-content/mu-plugins/whp-maintenance.php").write_text("<?php")
        self.assertTrue(sw.wp_switches({"domain": "klant.nl"})["maintenance"])
        self.assertFalse(sw.wp_switches({"domain": "klant.nl", "path": "shop"})["maintenance"])

    def test_maintenance_text_is_limited(self):
        with self.assertRaises(ActionError):
            sw.wp_maintenance_set({"domain": "klant.nl", "enabled": True, "message": "x" * 1001})

    # --- debug

    def test_debug_on_logs_outside_the_docroot_and_never_on_screen(self):
        sw.wp_debug_set({"domain": "klant.nl", "path": "shop", "enabled": True})
        sets = [a for a in self.fake.wp_args() if a[:2] == ["config", "set"]]
        self.assertEqual(["WP_DEBUG", "true", "--raw"], sets[0][2:5])
        self.assertEqual(["WP_DEBUG_LOG", str(self.site_root / "logs/wp-debug-shop.log")], sets[1][2:4])
        self.assertEqual(["WP_DEBUG_DISPLAY", "false", "--raw"], sets[2][2:5])

    def test_debug_off(self):
        sw.wp_debug_set({"domain": "klant.nl", "enabled": False})
        sets = [a[2:4] for a in self.fake.wp_args() if a[:2] == ["config", "set"]]
        self.assertEqual([["WP_DEBUG", "false"], ["WP_DEBUG_LOG", "false"], ["WP_DEBUG_DISPLAY", "false"]], sets)

    def test_debug_falls_back_when_wp_config_has_no_anchor(self):
        calls = {"n": 0}
        orig = self.fake.__call__

        def flaky(argv, **kw):
            if wordpress.WP_CLI in argv and "config" in argv and "--anchor=<?php" not in argv and calls["n"] == 0:
                calls["n"] += 1
                return SimpleNamespace(stdout="", stderr="Error: Unable to locate placement anchor.", returncode=1)
            return orig(argv, **kw)

        with mock.patch.object(wordpress, "run", flaky):
            sw.wp_debug_set({"domain": "klant.nl", "enabled": True})
        self.assertTrue(any("--anchor=<?php" in a for a in self.fake.wp_args()))

    def test_debug_needs_the_logs_folder(self):
        os.rmdir(self.site_root / "logs")
        with self.assertRaises(ActionError) as e:
            sw.wp_debug_set({"domain": "klant.nl", "enabled": True})
        self.assertEqual("conflict", e.exception.code)

    # --- indexing and caches

    def test_indexing(self):
        sw.wp_indexing_set({"domain": "klant.nl", "enabled": False})
        self.assertIn(["option", "update", "blog_public", "0"], [a[:4] for a in self.fake.wp_args()])

    def test_cache_clear_runs_every_step_and_reports_plugins(self):
        out = sw.wp_cache_clear({"domain": "klant.nl"})
        self.assertEqual(["page_cache", "object_cache", "plugins", "transients"], [s["step"] for s in out["steps"]])
        self.assertTrue(out["ok"])
        self.assertEqual(["WP Rocket"], out["steps"][2]["plugins"])
        evals = [c["argv"] for c in self.fake.calls if wordpress.WP_CLI in c["argv"] and "eval" in c["argv"]]
        self.assertNotIn("--skip-plugins", evals[0])  # cache plugins must be loaded

    def test_cache_clear_keeps_going_when_a_plugin_breaks(self):
        self.fake.fail["eval " + sw._PLUGINS_PHP] = "PHP Fatal error: broken plugin"
        out = sw.wp_cache_clear({"domain": "klant.nl"})
        self.assertFalse(out["ok"])
        self.assertEqual([True, True, False, True], [s["ok"] for s in out["steps"]])
        self.assertIn("Fatal error", out["steps"][2]["error"])

    # --- debug log

    LOG = (
        "[07-Nov-2026 09:00:00 UTC] PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly.\n"
        "[07-Nov-2026 09:01:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /x.php:3\n"
        "Stack trace:\n#0 {main}\n  thrown in /x.php on line 3\n"
        "[07-Nov-2026 09:02:00 UTC] PHP Deprecated:  Creation of dynamic property\n"
        "[07-Nov-2026 09:03:00 UTC] WordPress database error Table 'wp_x' doesn't exist\n"
    )

    def test_debug_log_entries_newest_first_with_levels_and_stack_traces(self):
        (self.site_root / "logs/wp-debug.log").write_text(self.LOG)
        out = sw.wp_debug_log({"domain": "klant.nl"})
        self.assertEqual(["error", "deprecated", "error", "notice"], [e["level"] for e in out["entries"]])
        self.assertIn("Stack trace:\n#0 {main}", out["entries"][2]["message"])
        self.assertEqual({"error": 2, "warning": 0, "notice": 1, "deprecated": 1, "other": 0}, out["counts"])
        self.assertEqual("07-Nov-2026 09:03:00", out["entries"][0]["time"])

    def test_web_errors_from_the_php_log_and_cron_errors_are_merged_by_time(self):
        (self.site_root / "logs/wp-debug.log").write_text("[07-Nov-2026 09:05:00 UTC] PHP Warning:  from cron\n")
        (self.site_root / "logs/php-error.log").write_text(
            "[07-Nov-2026 09:04:00 UTC] PHP Notice:  from web\n[07-Nov-2026 09:06:00 UTC] PHP Fatal error:  later web\n")
        out = sw.wp_debug_log({"domain": "klant.nl"})
        self.assertEqual([("web", "error"), ("cron", "warning"), ("web", "notice")], [(e["source"], e["level"]) for e in out["entries"]])
        self.assertEqual(["web", "cron"], [f["source"] for f in out["files"]])
        self.assertEqual(1, len(sw.wp_debug_log({"domain": "klant.nl", "source": "cron"})["entries"]))
        self.assertEqual(out["size"], sw.wp_switches({"domain": "klant.nl"})["log"]["size"])
        sw.wp_debug_log_clear({"domain": "klant.nl"})
        self.assertEqual("", (self.site_root / "logs/php-error.log").read_text())

    def test_debug_log_filters(self):
        (self.site_root / "logs/wp-debug.log").write_text(self.LOG)
        self.assertEqual(1, len(sw.wp_debug_log({"domain": "klant.nl", "level": "notice"})["entries"]))
        self.assertEqual(1, len(sw.wp_debug_log({"domain": "klant.nl", "q": "UNDEFINED FUNCTION"})["entries"]))
        self.assertEqual(1, len(sw.wp_debug_log({"domain": "klant.nl", "limit": 1})["entries"]))

    def test_debug_log_reads_only_the_end_of_a_big_file(self):
        with mock.patch.object(sw, "READ_BYTES", 200):
            (self.site_root / "logs/wp-debug.log").write_text(self.LOG * 5)
            out = sw.wp_debug_log({"domain": "klant.nl"})
        self.assertTrue(out["truncated"])
        self.assertTrue(all(e["time"] for e in out["entries"]))

    def test_missing_debug_log(self):
        out = sw.wp_debug_log({"domain": "klant.nl"})
        self.assertFalse(out["exists"])
        self.assertEqual([], out["entries"])

    def test_debug_log_refuses_links(self):
        (self.site_root / "secret").write_text("root only\n")
        (self.site_root / "logs/wp-debug.log").symlink_to(self.site_root / "secret")
        with self.assertRaises(ActionError):
            sw.wp_debug_log({"domain": "klant.nl"})
        os.unlink(self.site_root / "logs/wp-debug.log")
        os.link(self.site_root / "secret", self.site_root / "logs/wp-debug.log")
        with self.assertRaises(ActionError):
            sw.wp_debug_log_clear({"domain": "klant.nl"})
        self.assertEqual("root only\n", (self.site_root / "secret").read_text())

    def test_logs_folder_link_is_refused(self):
        os.rmdir(self.site_root / "logs")
        (self.site_root / "elsewhere").mkdir()
        (self.site_root / "elsewhere/wp-debug.log").write_text(self.LOG)
        (self.site_root / "logs").symlink_to(self.site_root / "elsewhere")
        with self.assertRaises(ActionError):
            sw.wp_debug_log({"domain": "klant.nl"})

    def test_clear_debug_log(self):
        log = self.site_root / "logs/wp-debug.log"
        log.write_text(self.LOG)
        self.assertTrue(sw.wp_debug_log_clear({"domain": "klant.nl"})["cleared"])
        self.assertEqual("", log.read_text())

    def test_delete_public_debug_log_as_site_user(self):
        sw.wp_debug_log_clear({"domain": "klant.nl", "which": "public"})
        (cmd, _), = self.fake.user_cmds()
        self.assertEqual(["/usr/bin/rm", "-f", "--", "wp-content/debug.log"], cmd)


if __name__ == "__main__":
    unittest.main()
