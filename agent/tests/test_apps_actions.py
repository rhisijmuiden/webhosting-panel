"""apps.* and db.* actions with the system commands mocked out.

The real downloads and installs (WordPress through WP-CLI, MariaDB) run in
the Debian 13 smoke test (tests/smoke.sh).
"""

import json
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
from whp_agent.actions import apps, databases  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def wait(job):
    for _ in range(200):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


class FakeRecipe:
    id = "fake"
    name = "Fake CMS"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "admin/"

    def __init__(self, fail=False):
        self.fail = fail

    def install(self, ctx):
        Path(ctx.target, "index.php").write_text("<?php echo 'hi';")
        Path(ctx.target, "wp-content").mkdir()
        ctx.version = "1.2.3"
        if self.fail:
            raise ActionError("command_failed", "boom")

    def nginx_snippet(self, ctx):
        return None

    def data_dirs(self, ctx):
        return []


class AppsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.site_root = root / "vhosts" / "klant.nl"
        self.docroot = self.site_root / "public"
        self.docroot.mkdir(parents=True)
        (self.site_root / "tmp").mkdir()
        (self.docroot / "index.html").write_text(
            '<!doctype html><meta charset="utf-8"><title>klant.nl</title><p>klant.nl</p>\n')
        self.site = {"domain": "klant.nl", "user": "s_klant_nl", "php": "8.4", "root": str(self.site_root),
                     "docroot": str(self.docroot), "ssl": "active", "cache": True, "aliases": ["www.klant.nl"],
                     "suspended": False}
        self._saved = (paths.STATE_DIR, paths.JOBS_DIR, dict(apps.RECIPES))
        paths.STATE_DIR = str(root / "state")
        paths.JOBS_DIR = str(root / "state" / "jobs")
        self.sql = []
        self.commands = []
        patches = [
            mock.patch.object(apps, "site_info", lambda d: dict(self.site)),
            mock.patch.object(databases, "sql", lambda s, timeout=60: self.sql.append(s) or ""),
            mock.patch.object(apps, "run", self._run),
            mock.patch.object(apps, "put_placeholder", self._placeholder),
            mock.patch.object(apps.Context, "as_site", lambda ctx, argv, **kw: self._as_site(ctx, argv, **kw)),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)

    def tearDown(self):
        paths.STATE_DIR, paths.JOBS_DIR, recipes = self._saved
        apps.RECIPES.clear()
        apps.RECIPES.update(recipes)
        apps._busy.clear()
        self.tmp.cleanup()

    def _run(self, argv, **kw):
        self.commands.append(list(argv))
        return CompletedProcess(argv, 0, "{}", "")

    def _as_site(self, ctx, argv, **kw):
        self.commands.append(["as-site", *argv])
        if argv[:2] == ["mkdir", "-p"]:
            os.makedirs(argv[-1], exist_ok=True)
        return CompletedProcess(argv, 0, "", "")

    def _placeholder(self, user, docroot, domain):
        Path(docroot, "index.html").write_text(f"<title>{domain}</title>")

    def params(self, **over):
        p = {"domain": "klant.nl", "app": "fake", "title": "Mijn site", "admin_user": "beheer",
             "admin_email": "info@klant.nl", "admin_password": "a-very-long-secret", "locale": "nl"}
        p.update(over)
        return p

    def test_catalog_lists_the_recipes(self):
        ids = [a["id"] for a in apps.apps_catalog({})["apps"]]
        self.assertEqual(ids, ["wordpress", "joomla", "nextcloud"])

    def test_install_into_root_replaces_the_placeholder(self):
        apps.RECIPES["fake"] = FakeRecipe()
        state = wait(apps.apps_install(self.params()))
        self.assertEqual(state["status"], "succeeded", state)
        result = state["result"]
        self.assertEqual(result["url"], "https://klant.nl")
        self.assertEqual(result["admin_url"], "https://klant.nl/admin/")
        self.assertEqual(result["version"], "1.2.3")
        self.assertFalse((self.docroot / "index.html").exists())
        self.assertTrue((self.docroot / "index.php").exists())
        self.assertTrue(any("CREATE DATABASE `klant_nl_fake_" in s for s in self.sql))
        listed = apps.apps_list({"domain": "klant.nl"})["apps"]
        self.assertEqual(len(listed), 1)
        self.assertNotIn("admin_password", json.dumps(listed))
        self.assertNotIn("a-very-long-secret", json.dumps(state))

    def test_install_in_subfolder_and_conflicts(self):
        apps.RECIPES["fake"] = FakeRecipe()
        state = wait(apps.apps_install(self.params(path="blog")))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual(state["result"]["url"], "https://klant.nl/blog")
        self.assertTrue((self.docroot / "index.html").exists())  # root untouched
        for path in ["blog", "", "blog/sub"]:
            with self.assertRaises(ActionError) as ctx:
                apps.apps_install(self.params(path=path))
            self.assertEqual(ctx.exception.code, "conflict")

    def test_refuses_a_folder_with_files(self):
        apps.RECIPES["fake"] = FakeRecipe()
        (self.docroot / "index.php").write_text("<?php // the customer's own site")
        with self.assertRaises(ActionError) as ctx:
            apps.apps_install(self.params())
        self.assertEqual(ctx.exception.code, "conflict")

    def test_failed_install_rolls_back(self):
        apps.RECIPES["fake"] = FakeRecipe(fail=True)
        state = wait(apps.apps_install(self.params()))
        self.assertEqual(state["status"], "failed")
        self.assertEqual(sorted(os.listdir(self.docroot)), ["index.html"])
        self.assertTrue(any("DROP DATABASE IF EXISTS" in s for s in self.sql))
        self.assertEqual(apps.apps_list({"domain": "klant.nl"})["apps"], [])
        self.assertEqual(databases.owned_by("klant.nl"), [])

    def test_remove(self):
        apps.RECIPES["fake"] = FakeRecipe()
        item = wait(apps.apps_install(self.params(path="shop")))["result"]
        state = wait(apps.apps_remove({"domain": "klant.nl", "id": item["id"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertFalse((self.docroot / "shop").exists())
        self.assertEqual(apps.apps_list({"domain": "klant.nl"})["apps"], [])
        self.assertEqual(databases.owned_by("klant.nl"), [])

    def test_validation(self):
        apps.RECIPES["fake"] = FakeRecipe()
        bad = [
            {"app": "drupal"},
            {"path": "../etc"},
            {"path": "a b"},
            {"admin_password": "short"},
            {"admin_user": "x"},
            {"admin_email": "not-an-email"},
            {"title": "a\nb"},
        ]
        for over in bad:
            with self.assertRaises(ActionError, msg=over):
                apps.apps_install(self.params(**over))
        with self.assertRaises(ActionError):
            apps.apps_install(self.params(app="nextcloud", path="cloud"))

    def test_clear_target_refuses_symlinks(self):
        outside = Path(self.tmp.name) / "outside"
        outside.mkdir()
        (outside / "keep.txt").write_text("x")
        (self.docroot / "evil").symlink_to(outside)
        with self.assertRaises(ActionError):
            apps.clear_target("klant.nl", str(self.site_root), str(self.docroot), "evil")
        self.assertTrue((outside / "keep.txt").exists())
        # Clearing the root removes the link itself, never what it points to.
        apps.clear_target("klant.nl", str(self.site_root), str(self.docroot), "")
        self.assertTrue((outside / "keep.txt").exists())
        self.assertEqual(os.listdir(self.docroot), [])

    def test_drop_only_own_databases(self):
        db = databases.create_for_site("klant.nl", "wp")
        with self.assertRaises(ActionError):
            databases.drop(db["name"], "ander.nl")
        with self.assertRaises(ActionError):
            databases.drop("x`; DROP DATABASE mysql; --", "klant.nl")
        self.assertTrue(databases.drop(db["name"], "klant.nl")["dropped"])

    def test_wordpress_config_escapes_and_has_salts(self):
        ctx = apps.Context(self.site, "wordpress", "", self.params(), lambda _l: None)
        ctx.db = {"name": "klant_nl_wp_ab12", "user": "klant_nl_wp_ab12", "password": "p'w\\d"}
        conf = apps.WordPress().config(ctx)
        self.assertIn("define('DB_PASSWORD', 'p\\'w\\\\d');", conf)
        self.assertEqual(conf.count("_KEY'"), 4)
        self.assertIn("DISALLOW_FILE_EDIT", conf)

    def test_wordpress_uses_the_shared_wp_cli_runner(self):
        from whp_agent import wordpress
        calls = []

        def fake_run(argv, timeout=60, input_text=None, **kw):
            calls.append((argv, input_text))
            return CompletedProcess(argv, 0, "6.9\n", "")

        wsite = {"domain": "klant.nl", "id": "klant_nl", "user": "s_klant_nl", "php": "8.4",
                 "root": str(self.site_root), "docroot": str(self.docroot), "ssl": "active", "aliases": []}
        ctx = apps.Context(self.site, "wordpress", "", self.params(), lambda _l: None)
        ctx.db = {"name": "klant_nl_wp_ab12", "user": "klant_nl_wp_ab12", "password": "x"}
        with mock.patch.object(wordpress, "site_info", lambda d: wsite), \
                mock.patch.object(apps, "run", fake_run), \
                mock.patch.object(apps.WordPress, "wp_cli", lambda self: "/usr/local/bin/wp"), \
                mock.patch.object(apps.Context, "write_private", lambda *a, **k: None):
            apps.WordPress().install(ctx)
        argv0 = calls[0][0]
        self.assertEqual(argv0[0], "/usr/bin/systemd-run")
        self.assertIn("--uid=s_klant_nl", argv0)
        self.assertIn("UMask=0022", argv0)
        self.assertIn("--locale=nl_NL", argv0)
        install = next(c for c in calls if "install" in c[0] and "core" in c[0])
        self.assertEqual(install[1], "a-very-long-secret\n")
        self.assertFalse(any("a-very-long-secret" in a for c in calls for a in c[0]))


if __name__ == "__main__":
    unittest.main()
