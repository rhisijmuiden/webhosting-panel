"""WordPress integrity check: parsing WP-CLI verify-checksums, the stored report, the
malware cross-reference, the restore plan and the restore worker itself."""

import hashlib
import json
import os
import sys
import tempfile
import time
import unittest
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths, wordpress  # noqa: E402
from whp_agent import wp_integrity_worker as worker  # noqa: E402
from whp_agent.actions import wp_integrity as wpi  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

REAL_DOWNLOAD = worker.download

CORE_CHANGED = (
    "Warning: File doesn't verify against checksum: wp-includes/functions.php\n"
    "Warning: File should not exist: wp-admin/shell.php\n"
    "Warning: File doesn't exist: wp-includes/version-x.php\n"
    "Error: WordPress installation doesn't verify against checksums.\n"
)


def make_wp(d, version="6.6.2", locale=None):
    (d / "wp-includes").mkdir(parents=True)
    (d / "wp-admin").mkdir(parents=True)
    (d / "wp-content/plugins/akismet").mkdir(parents=True)
    (d / "wp-config.php").write_text("<?php\n")
    extra = f"$wp_local_package = '{locale}';\n" if locale else ""
    (d / "wp-includes/version.php").write_text(f"<?php\n$wp_version = '{version}';\n{extra}")
    (d / "wp-includes/functions.php").write_text("<?php // hacked\n")
    (d / "wp-admin/shell.php").write_text("<?php eval($_POST['x']);\n")
    (d / "wp-content/plugins/akismet/akismet.php").write_text("<?php // changed\n")


class FakeWp:
    def __init__(self):
        self.calls = []
        self.core = (CORE_CHANGED, 1)
        self.plugins_json = [{"plugin_name": "akismet", "file": "akismet.php", "message": "Checksum does not match"}]
        self.plugins_stderr = "Warning: Could not retrieve the checksums for version 2.1 of plugin premium-seo, skipping.\n"
        self.worker = None

    def __call__(self, argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
        self.calls.append(list(argv))
        if wordpress.WP_CLI in argv:
            args = argv[argv.index(wordpress.WP_CLI) + 3:]
            if args[:2] == ["core", "verify-checksums"]:
                return SimpleNamespace(stdout="", stderr=self.core[0], returncode=self.core[1])
            if args[:2] == ["plugin", "list"]:
                return SimpleNamespace(stdout=json.dumps([
                    {"name": "akismet", "title": "Akismet", "version": "5.3", "status": "active"},
                    {"name": "premium-seo", "title": "Premium SEO", "version": "2.1", "status": "inactive"},
                    {"name": "hello", "title": "Hello", "version": "1.7", "status": "inactive"},
                    {"name": "object-cache.php", "title": "drop-in", "version": "", "status": "dropin"},
                ]), stderr="", returncode=0)
            if args[:2] == ["plugin", "verify-checksums"]:
                return SimpleNamespace(stdout=json.dumps(self.plugins_json), stderr=self.plugins_stderr, returncode=1)
        if wpi.WORKER in argv:
            self.worker = json.loads(input_text)
            return SimpleNamespace(stdout=json.dumps({"ok": True, "results": [
                {"key": i["key"], "restored": [{"file": f, "md5": "x"} for f in i["restore"]], "quarantined": i["remove"], "errors": []}
                for i in self.worker["items"]]}), stderr="", returncode=0)
        return SimpleNamespace(stdout="", stderr="", returncode=0)


class IntegrityTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.root = root
        (root / "sites").mkdir()
        (root / "sites/klant.nl.env").write_text(
            "SITE_DOMAIN=klant.nl\nSITE_ID=klant_nl\nSITE_USER=s_klant_nl\nSITE_PHP=8.4\nSITE_DOCROOT_REL=public\n")
        self.docroot = root / "vhosts/klant.nl/public"
        make_wp(self.docroot)
        (root / "malware").mkdir()
        self.fake = FakeWp()
        self.patches = [
            mock.patch.object(wordpress, "SITES_DB", str(root / "sites")),
            mock.patch.dict(os.environ, {"WHP_VHOSTS": str(root / "vhosts")}),
            mock.patch.object(wordpress, "run", self.fake),
            mock.patch.object(wordpress, "ensure_cli", lambda: wordpress.WP_CLI),
            mock.patch.object(wpi, "run", self.fake),
            mock.patch.object(wpi, "STATE", root / "state/wp-integrity"),
            mock.patch.object(wpi, "MALWARE_DIR", str(root / "malware")),
            mock.patch.object(paths, "JOBS_DIR", str(root / "jobs")),
        ]
        for p in self.patches:
            p.start()

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

    def test_check_reports_changed_core_and_plugin_files(self):
        r = wpi.wpint_check({"domain": "klant.nl"})
        self.assertEqual("changed", r["status"])
        self.assertEqual(4, r["changed_files"])
        core = r["components"][0]
        self.assertEqual(("core", "changed", "6.6.2", "en_US"), (core["type"], core["status"], core["version"], core["locale"]))
        self.assertEqual([("wp-includes/functions.php", "modified"), ("wp-admin/shell.php", "added"), ("wp-includes/version-x.php", "missing")],
                         [(f["file"], f["issue"]) for f in core["files"]])
        self.assertEqual(hashlib.md5(b"<?php // hacked\n").hexdigest(), core["files"][0]["md5"])
        self.assertNotIn("md5", core["files"][2])
        plugins = {p["slug"]: p for p in r["components"][1:]}
        self.assertEqual({"akismet", "premium-seo", "hello"}, set(plugins))
        self.assertEqual("changed", plugins["akismet"]["status"])
        self.assertEqual("wp-content/plugins/akismet", plugins["akismet"]["base"])
        self.assertEqual("unverifiable", plugins["premium-seo"]["status"])
        self.assertEqual("ok", plugins["hello"]["status"])
        # Stored for wpint.report.
        self.assertEqual(r, wpi.wpint_report({"domain": "klant.nl"}))
        # Checks run as WP-CLI, never as PHP of root.
        self.assertTrue(all("--uid=s_klant_nl" in c for c in self.fake.calls if wordpress.WP_CLI in c))

    def test_clean_install_and_unreachable_checksums(self):
        self.fake.core = ("Success: WordPress installation verifies against checksums.\n", 0)
        self.fake.plugins_json = []
        self.fake.plugins_stderr = ""
        r = wpi.check("klant.nl", "")
        self.assertEqual(("ok", 0), (r["status"], r["changed_files"]))
        self.fake.core = ("Error: Couldn't get checksums from WordPress.org.\n", 1)
        self.assertEqual("unverifiable", wpi.check("klant.nl", "")["components"][0]["status"])

    def test_locale_is_read_for_the_original_download(self):
        (self.docroot / "wp-includes/version.php").write_text("<?php\n$wp_version = '6.7.1';\n$wp_local_package = 'nl_NL';\n")
        r = wpi.check("klant.nl", "")
        self.assertEqual("nl_NL", r["locale"])
        self.assertEqual(("https://downloads.wordpress.org/release/nl_NL/wordpress-6.7.1.zip", "wordpress/"), wpi._zip_url(r["components"][0]))
        plugin = next(c for c in r["components"] if c["slug"] == "akismet")
        self.assertEqual(("https://downloads.wordpress.org/plugin/akismet.5.3.zip", "akismet/"), wpi._zip_url(plugin))

    def test_malware_hits_are_linked_to_changed_files(self):
        (self.root / "malware/klant.nl.json").write_text(json.dumps({"scanned_at": "2026-11-07T03:40:00Z", "findings": [
            {"engine": "yara", "file": str(self.docroot / "wp-admin/shell.php"), "signature": "PHP.Eval"},
            {"engine": "clamav", "file": str(self.docroot / "wp-content/uploads/x.php"), "signature": "Php.Webshell"},
        ]}))
        r = wpi.check("klant.nl", "")
        shell = next(f for f in r["components"][0]["files"] if f["file"] == "wp-admin/shell.php")
        self.assertEqual("PHP.Eval", shell["malware"])
        self.assertEqual([{"file": "wp-content/uploads/x.php", "signature": "Php.Webshell"}], r["malware_elsewhere"])

    def test_restore_only_takes_files_from_the_last_check(self):
        with self.assertRaises(ActionError):
            wpi.wpint_restore({"domain": "klant.nl", "items": [{"type": "core", "slug": "wordpress"}]})
        wpi.check("klant.nl", "")
        report = wpi.load("klant.nl", "")
        items = wpi.plan(report, [wpi._selection({"type": "core", "slug": "wordpress", "files": ["wp-includes/functions.php", "/etc/passwd"]})])
        self.assertEqual(["wp-includes/functions.php"], items[0]["restore"])
        self.assertEqual([], items[0]["remove"])
        items = wpi.plan(report, [wpi._selection({"type": "core", "slug": "wordpress", "quarantine_added": True})])
        self.assertEqual(["wp-includes/functions.php", "wp-includes/version-x.php"], items[0]["restore"])
        self.assertEqual(["wp-admin/shell.php"], items[0]["remove"])
        with self.assertRaises(ActionError):
            wpi.plan(report, [wpi._selection({"type": "plugin", "slug": "hello"})])
        for bad in [{"type": "theme", "slug": "x"}, {"type": "plugin", "slug": "../x"}, {"type": "core", "slug": "wordpress", "files": "x"}]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                wpi._selection(bad)

    def test_restore_job_runs_the_worker_as_the_site_user_and_checks_again(self):
        wpi.check("klant.nl", "")
        job = wpi.wpint_restore({"domain": "klant.nl", "items": [
            {"type": "core", "slug": "wordpress", "files": "all", "quarantine_added": True},
            {"type": "plugin", "slug": "akismet"},
        ]})
        state = self.wait(job)
        self.assertEqual("succeeded", state["status"], state)
        self.assertEqual(3, state["result"]["restored"])
        self.assertEqual(1, state["result"]["quarantined"])
        argv = next(c for c in self.fake.calls if wpi.WORKER in c)
        self.assertIn("--uid=s_klant_nl", argv)
        self.assertEqual(str(self.root / "vhosts/klant.nl/quarantine/wordpress-integrity"), os.path.dirname(self.fake.worker["quarantine"]))
        self.assertEqual("wp-content/plugins/akismet", self.fake.worker["items"][1]["base"])
        self.assertIn("report", state["result"])


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.install = self.root / "public"
        make_wp(self.install)
        self.zip = self.root / "dl/original.zip"
        self.zip.parent.mkdir()
        with zipfile.ZipFile(self.zip, "w") as zf:
            zf.writestr("wordpress/wp-includes/functions.php", "<?php // original\n")
            zf.writestr("wordpress/wp-includes/version-x.php", "<?php // missing one\n")
            zf.writestr("akismet/akismet.php", "<?php // akismet original\n")
        patch = mock.patch.object(worker, "download", lambda url, tmp: str(self.zip))
        patch.start()
        self.addCleanup(patch.stop)
        self.addCleanup(self.tmp.cleanup)

    def test_restores_files_and_quarantines_added_ones(self):
        q = self.root / "quarantine/x"
        out = worker.handle({"install_dir": str(self.install), "quarantine": str(q), "items": [
            {"key": "core", "url": "https://downloads.wordpress.org/release/wordpress-6.6.2.zip", "prefix": "wordpress/", "base": "",
             "restore": ["wp-includes/functions.php", "wp-includes/version-x.php"], "remove": ["wp-admin/shell.php"]},
            {"key": "plugin:akismet", "url": "https://downloads.wordpress.org/plugin/akismet.5.3.zip", "prefix": "akismet/",
             "base": "wp-content/plugins/akismet", "restore": ["akismet.php"], "remove": []},
        ]})
        self.assertTrue(out["ok"])
        self.assertEqual([], out["results"][0]["errors"])
        self.assertEqual("<?php // original\n", (self.install / "wp-includes/functions.php").read_text())
        self.assertTrue((self.install / "wp-includes/version-x.php").exists())
        self.assertFalse((self.install / "wp-admin/shell.php").exists())
        self.assertTrue((q / "wp-admin/shell.php").exists())
        self.assertEqual("<?php // akismet original\n", (self.install / "wp-content/plugins/akismet/akismet.php").read_text())
        self.assertEqual(0o644, os.stat(self.install / "wp-includes/functions.php").st_mode & 0o777)

    def test_refuses_paths_outside_the_install_and_symlinks(self):
        (self.install / "wp-includes/evil").symlink_to(self.root)
        out = worker.handle({"install_dir": str(self.install), "quarantine": "", "items": [
            {"key": "core", "url": "https://downloads.wordpress.org/x.zip", "prefix": "wordpress/", "base": "",
             "restore": ["wp-includes/evil/functions.php"], "remove": []}]})
        self.assertTrue(out["results"][0]["errors"])
        for bad in ["../x", "/etc/passwd", "a/../../b", ""]:
            with self.subTest(bad=bad), self.assertRaises(worker.Fail):
                worker.clean_rel(bad)

    def test_only_downloads_from_wordpress_org(self):
        with self.assertRaises(worker.Fail):
            REAL_DOWNLOAD("https://evil.example/wordpress.zip", str(self.root))

if __name__ == "__main__":
    unittest.main()
