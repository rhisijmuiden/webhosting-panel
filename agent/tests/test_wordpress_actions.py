"""WordPress toolkit: detection, WP-CLI command lines, update/rollback flow, WP-cron
timers, passwords and login links. WP-CLI itself is faked: these tests check what
the agent asks it to do."""

import json
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths, wordpress  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def make_wp(d, version="6.6.2"):
    (d / "wp-includes").mkdir(parents=True)
    (d / "wp-content/plugins").mkdir(parents=True)
    (d / "wp-content/themes").mkdir(parents=True)
    (d / "wp-config.php").write_text("<?php\n")
    (d / "wp-includes/version.php").write_text(f"<?php\n$wp_version = '{version}';\n$wp_db_version = 57155;\n")


class FakeWp:
    """Stands in for run(): records every argv and answers like WP-CLI would."""

    def __init__(self):
        self.calls = []
        self.health = [200, 200, 200]
        self.answers = {}

    def __call__(self, argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
        self.calls.append({"argv": list(argv), "input": input_text})
        out = ""
        if argv[0] == "curl":
            out = str(self.health.pop(0) if self.health else 200)
        elif wordpress.WP_CLI in argv:
            args = argv[argv.index(wordpress.WP_CLI) + 3:]
            out = self.answer(args)
        return SimpleNamespace(stdout=out, stderr="", returncode=0)

    def answer(self, args):
        key = " ".join(a for a in args[:2])
        if key in self.answers:
            return self.answers[key]
        if args[:2] == ["option", "get"]:
            return "https://klant.nl"
        if args[:2] == ["user", "list"]:
            return json.dumps([{"ID": "1", "user_login": "beheer", "user_email": "a@klant.nl", "display_name": "Beheer"}])
        if args[:2] == ["core", "check-update"]:
            return json.dumps([{"version": "6.7.1", "update_type": "major", "package_url": "x"}])
        if args[:2] == ["plugin", "list"]:
            return json.dumps([
                {"name": "akismet", "title": "Akismet", "status": "active", "version": "5.0", "update": "available", "update_version": "5.3", "auto_update": "off"},
                {"name": "hello", "title": "Hello", "status": "inactive", "version": "1.7", "update": "none", "update_version": "", "auto_update": "off"},
                {"name": "object-cache.php", "title": "drop-in", "status": "dropin", "version": "", "update": "none"},
            ])
        if args[:2] == ["theme", "list"]:
            return json.dumps([{"name": "twentytwentyfour", "title": "TT4", "status": "active", "version": "1.0", "update": "available", "update_version": "1.2", "auto_update": "off"}])
        if args[:2] == ["plugin", "update"]:
            return "Success: Updated 1 of 1 plugins.\n" + json.dumps([{"name": "akismet", "old_version": "5.0", "new_version": "5.3", "status": "Updated"}])
        if args[:2] == ["theme", "update"]:
            return json.dumps([{"name": "twentytwentyfour", "old_version": "1.0", "new_version": "1.2", "status": "Updated"}])
        return ""

    def wp_calls(self):
        return [c for c in self.calls if wordpress.WP_CLI in c["argv"]]

    def wp_args(self):
        return [c["argv"][c["argv"].index(wordpress.WP_CLI) + 3:] for c in self.wp_calls()]


class WordPressTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.root = root
        (root / "sites").mkdir()
        (root / "sites/klant.nl.env").write_text(
            "SITE_DOMAIN=klant.nl\nSITE_ID=klant_nl\nSITE_USER=s_klant_nl\nSITE_PHP=8.4\n"
            "SITE_DOCROOT_REL=public\nSITE_SSL=active\nSITE_ALIASES=www.klant.nl\n")
        self.docroot = root / "vhosts/klant.nl/public"
        make_wp(self.docroot)
        make_wp(self.docroot / "blog", "6.4.0")
        (self.docroot / "wp-content/uploads").mkdir()
        self.fake = FakeWp()
        self.patches = [
            mock.patch.object(wordpress, "SITES_DB", str(root / "sites")),
            mock.patch.dict(os.environ, {"WHP_VHOSTS": str(root / "vhosts")}),
            mock.patch.object(wordpress, "run", self.fake),
            mock.patch.object(wpa, "run", self.fake),
            mock.patch.object(wpa, "STATE", root / "state/wordpress"),
            mock.patch.object(wpa, "SNAPSHOTS", root / "state/wordpress/snapshots"),
            mock.patch.object(wpa, "SYSTEMD_DIR", root / "systemd"),
            mock.patch.object(paths, "JOBS_DIR", str(root / "jobs")),
            mock.patch("shutil.chown"),
        ]
        for p in self.patches:
            p.start()
        (root / "systemd").mkdir()

    def tearDown(self):
        for p in reversed(self.patches):
            p.stop()
        self.tmp.cleanup()

    def wait(self, job):
        for _ in range(200):
            state = jobs.read(job["job"])
            if state["status"] != "running":
                return state
            time.sleep(0.05)
        raise AssertionError("job did not finish")

    # --- detection and paths

    def test_detect_finds_the_docroot_and_sub_folder_installs_without_running_php(self):
        found = wpa.wp_detect({"domain": "klant.nl"})["installs"]
        self.assertEqual([{"path": "", "version": "6.6.2"}, {"path": "blog", "version": "6.4.0"}], found)
        self.assertEqual([], self.fake.calls)

    def test_paths_cannot_leave_the_docroot(self):
        for bad in ["../x", "a/../../b", "/etc", "a b", "-rf"]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                wordpress.require_install("klant.nl", bad if bad != "/etc" else "..")
        (self.docroot / "evil").symlink_to("/etc")
        with self.assertRaises(ActionError):
            wordpress.require_install("klant.nl", "evil")
        with self.assertRaises(ActionError) as e:
            wordpress.require_install("onbekend.nl")
        self.assertEqual("not_found", e.exception.code)

    def test_wp_cli_runs_as_the_site_user_with_its_php_and_readable_umask(self):
        wordpress.wp("klant.nl", ["plugin", "list"])
        argv = self.fake.calls[0]["argv"]
        self.assertEqual("/usr/bin/systemd-run", argv[0])
        self.assertIn("--uid=s_klant_nl", argv)
        self.assertIn("--slice=whp-sites.slice", argv)
        self.assertIn("UMask=0022", argv)
        self.assertIn("--expand-environment=no", argv)  # wp eval code has $variables
        self.assertIn("/usr/bin/php8.4", argv)
        self.assertIn(f"--path={self.docroot}", argv)
        self.assertEqual(["--skip-plugins", "--skip-themes"], argv[-2:])
        self.assertNotIn("--allow-root", argv)

    # --- status

    def test_status_lists_outdated_parts_and_skips_dropins(self):
        data = wpa.wp_status({"domain": "klant.nl"})
        self.assertEqual("6.6.2", data["version"])
        self.assertEqual({"version": "6.7.1", "type": "major"}, data["core_update"])
        self.assertEqual({"core": True, "plugins": 1, "themes": 1}, data["outdated"])
        self.assertEqual(["akismet", "hello"], [p["name"] for p in data["plugins"]])
        self.assertEqual("wordpress", data["cron"]["mode"])
        self.assertTrue((self.root / "state/wordpress/klant_nl-root.json").exists())

    # --- update

    def test_update_takes_a_snapshot_updates_everything_and_purges_the_cache(self):
        state = self.wait(wpa.wp_update({"domain": "klant.nl"}))
        self.assertEqual("succeeded", state["status"], state)
        args = self.fake.wp_args()
        self.assertEqual("db", args[1][0])  # after option get siteurl
        self.assertIn(["core", "update", "--skip-plugins", "--skip-themes"], args)
        self.assertIn(["plugin", "update", "--all", "--format=json", "--skip-plugins", "--skip-themes"], args)
        self.assertIn(["theme", "update", "--all", "--format=json", "--skip-plugins", "--skip-themes"], args)
        tar = [c["argv"] for c in self.fake.calls if "/usr/bin/tar" in c["argv"]]
        self.assertEqual(1, len(tar))
        self.assertIn("--uid=s_klant_nl", tar[0])
        self.assertTrue(any(c["argv"][:3] == [wpa.WHP_SITE, "cache", "klant.nl"] for c in self.fake.calls))
        result = state["result"]
        self.assertEqual([{"name": "akismet", "old_version": "5.0", "new_version": "5.3"}], result["updated"]["plugins"])
        self.assertFalse(result["rolled_back"])

    def test_update_of_selected_plugins_only(self):
        state = self.wait(wpa.wp_update({"domain": "klant.nl", "core": False, "plugins": ["akismet"], "themes": False,
                                         "translations": False, "snapshot": False}))
        self.assertEqual("succeeded", state["status"], state)
        args = self.fake.wp_args()
        self.assertIn(["plugin", "update", "akismet", "--format=json", "--skip-plugins", "--skip-themes"], args)
        self.assertFalse(any(a[:2] in (["core", "update"], ["theme", "update"], ["db", "export"]) for a in args))
        with self.assertRaises(ActionError):
            wpa.wp_update({"domain": "klant.nl", "plugins": ["; rm -rf /"]})

    def test_a_site_that_breaks_is_rolled_back(self):
        self.fake.health = [200, 500, 200]
        state = self.wait(wpa.wp_update({"domain": "klant.nl", "core": False, "themes": False}))
        self.assertEqual("failed", state["status"])
        self.assertTrue(state["error"]["details"]["rolled_back"])
        args = self.fake.wp_args()
        self.assertTrue(any(a[:2] == ["db", "import"] for a in args))
        rm = [c["argv"] for c in self.fake.calls if "/usr/bin/rm" in c["argv"]][0]
        self.assertEqual("wp-content/plugins/akismet", rm[-1])

    def test_bulk_update_runs_installs_one_after_the_other_and_reports_each(self):
        self.fake.health = [200, 200, 200, 200]
        state = self.wait(wpa.wp_update_many({"installs": [{"domain": "klant.nl"}, {"domain": "klant.nl", "path": "blog"},
                                                           {"domain": "klant.nl", "path": "geen-wp"}],
                                              "core": False, "themes": False, "snapshot": False}))
        self.assertEqual("succeeded", state["status"], state)
        res = state["result"]["results"]
        self.assertEqual([True, True, False], [r["ok"] for r in res])
        self.assertEqual(1, state["result"]["failed"])
        with self.assertRaises(ActionError):
            wpa.wp_update_many({"installs": [{"domain": "klant.nl", "path": "../x"}]})

    def test_only_two_snapshots_are_kept(self):
        for i in range(3):
            with mock.patch("time.strftime", return_value=f"20261106-00000{i}"):
                wpa.snapshot("klant.nl", "", lambda line: None)
        snaps = wpa.wp_snapshots({"domain": "klant.nl"})["snapshots"]
        self.assertEqual(["20261106-000002", "20261106-000001"], [s["id"] for s in snaps])

    # --- WP-cron

    def test_cron_takeover_writes_a_timer_and_sets_disable_wp_cron(self):
        data = wpa.wp_cron_set({"domain": "klant.nl", "mode": "system", "interval": 5})
        self.assertEqual("system", data["mode"])
        self.assertEqual(5, data["interval"])
        service = (self.root / "systemd/whp-wpcron-klant_nl-root.service").read_text()
        timer = (self.root / "systemd/whp-wpcron-klant_nl-root.timer").read_text()
        self.assertIn("User=s_klant_nl", service)
        self.assertIn("cron event run --due-now", service)
        self.assertIn("/usr/bin/php8.4", service)
        self.assertIn("OnCalendar=*:0/5", timer)
        self.assertIn(["config", "set", "DISABLE_WP_CRON", "true", "--raw", "--type=constant", "--skip-plugins", "--skip-themes"],
                      self.fake.wp_args())
        self.assertIn(["systemctl", "enable", "--now", "whp-wpcron-klant_nl-root.timer"], [c["argv"] for c in self.fake.calls])

        (self.root / "sites/klant.nl.env").write_text((self.root / "sites/klant.nl.env").read_text().replace("SITE_PHP=8.4", "SITE_PHP=8.5"))
        self.assertEqual(["whp-wpcron-klant_nl-root"], wpa.refresh_cron_units("klant.nl"))
        self.assertIn("/usr/bin/php8.5", (self.root / "systemd/whp-wpcron-klant_nl-root.service").read_text())

        data = wpa.wp_cron_set({"domain": "klant.nl", "mode": "wordpress"})
        self.assertEqual("wordpress", data["mode"])
        self.assertFalse((self.root / "systemd/whp-wpcron-klant_nl-root.timer").exists())
        self.assertTrue(any(a[:3] == ["config", "delete", "DISABLE_WP_CRON"] for a in self.fake.wp_args()))
        with self.assertRaises(ActionError):
            wpa.wp_cron_set({"domain": "klant.nl", "mode": "system", "interval": 2})

    def test_sub_folder_installs_get_their_own_timer(self):
        wpa.wp_cron_set({"domain": "klant.nl", "path": "blog", "mode": "system", "interval": 15})
        units = sorted(p.name for p in (self.root / "systemd").iterdir())
        self.assertEqual(2, len(units))
        self.assertTrue(all("root" not in u for u in units))

    # --- passwords and login

    def test_password_goes_over_stdin_never_on_the_command_line(self):
        out = wpa.wp_set_password({"domain": "klant.nl", "user_id": 1, "password": "Zeer-Geheim-2026!"})
        self.assertNotIn("password", out)
        call = [c for c in self.fake.wp_calls() if "eval" in c["argv"]][0]
        self.assertEqual("Zeer-Geheim-2026!\n", call["input"])
        self.assertFalse(any("Zeer-Geheim" in a for c in self.fake.calls for a in c["argv"]))

        generated = wpa.wp_set_password({"domain": "klant.nl", "user_id": 1})["password"]
        self.assertGreaterEqual(len(generated), 20)

        with self.assertRaises(ActionError):
            wpa.wp_set_password({"domain": "klant.nl", "user_id": 1, "password": "kort"})
        with self.assertRaises(ActionError) as e:
            wpa.wp_set_password({"domain": "klant.nl", "user_id": 7, "password": "Zeer-Geheim-2026!"})
        self.assertEqual("not_found", e.exception.code)

    def test_login_link_stores_only_a_hash_of_the_token(self):
        out = wpa.wp_login_link({"domain": "klant.nl"})
        self.assertTrue(out["url"].startswith("https://klant.nl/wp-login.php?whp_sso="))
        token = out["url"].split("=", 1)[1]
        transient = [a for a in self.fake.wp_args() if a[:2] == ["transient", "set"]][0]
        self.assertTrue(transient[2].startswith("whp_sso_"))
        self.assertNotIn(token, " ".join(transient))
        self.assertEqual(["1", "120"], transient[3:5])
        mu = [c for c in self.fake.calls if "/bin/sh" in c["argv"]][0]
        self.assertIn("whp_sso", mu["input"])
        self.assertIn("--uid=s_klant_nl", mu["argv"])

    def test_mu_plugin_is_valid_php(self):
        import shutil
        import subprocess
        if not shutil.which("php"):
            self.skipTest("php not installed")
        proc = subprocess.run(["php", "-l", str(wpa.MU_PLUGIN)], capture_output=True, text=True)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)


if __name__ == "__main__":
    unittest.main()
