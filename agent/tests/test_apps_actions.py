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
        self.wrappers = []
        self.releases = {}
        self.systemd = root / "systemd"
        self.systemd.mkdir()
        patches = [
            mock.patch.object(apps, "release_info", lambda r, refresh=False: self.releases.get(r.id, {})),
            mock.patch.object(apps, "SYSTEMD_DIR", str(self.systemd)),
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
        if argv and argv[0].startswith("php"):
            body = Path(argv[-1]).read_text() if argv[-1].endswith(".php") and os.path.isabs(argv[-1]) else ""
            self.wrappers.append((body, kw.get("env") or {}))
            if '"install"' in body and "run.php" in body:
                Path(ctx.target, "LocalSettings.php").write_text("<?php")
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
        self.assertEqual(ids, ["wordpress", "woocommerce", "joomla", "nextcloud", "piwigo", "mediawiki",
                               "dokuwiki", "phpbb", "freshrss"])

    def test_catalog_marks_unmaintained_apps(self):
        now = time.time()
        self.releases = {
            "wordpress": {"version": "6.9", "released": now - 20 * 86400},
            "joomla": {"version": "5.0.0", "released": now - 400 * 86400},
            "phpbb": {"version": "3.3.15", "released": None, "eol": True},
            "piwigo": {"version": "15.5.0", "released": None, "eol": False},
        }
        rows = {a["id"]: a for a in apps.apps_catalog({})["apps"]}
        self.assertTrue(rows["wordpress"]["maintained"])
        self.assertFalse(rows["joomla"]["maintained"])
        self.assertFalse(rows["phpbb"]["maintained"])
        self.assertTrue(rows["phpbb"]["end_of_life"])
        self.assertTrue(rows["piwigo"]["maintained"])
        self.assertIsNone(rows["mediawiki"]["maintained"])  # never checked
        self.assertFalse(rows["dokuwiki"]["database"])
        self.assertEqual(rows["phpbb"]["admin_user_pattern"], apps.PhpBB.admin_user_re)
        # A longer window lets the old Joomla release through again.
        rows = {a["id"]: a for a in apps.apps_catalog({"max_age_days": 500})["apps"]}
        self.assertTrue(rows["joomla"]["maintained"])

    def test_install_refuses_an_unmaintained_app(self):
        apps.RECIPES["fake"] = FakeRecipe()
        self.releases = {"fake": {"version": "1.0", "released": time.time() - 800 * 86400}}
        with self.assertRaises(ActionError) as ctx:
            apps.apps_install(self.params())
        self.assertEqual(ctx.exception.code, "forbidden")
        self.assertEqual(self.sql, [])

    def test_app_without_database(self):
        recipe = FakeRecipe()
        recipe.database = False
        apps.RECIPES["fake"] = recipe
        state = wait(apps.apps_install(self.params()))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertIsNone(state["result"]["database"])
        self.assertFalse(any("CREATE DATABASE" in s for s in self.sql))

    def test_timer_is_written_and_removed(self):
        recipe = FakeRecipe()
        recipe.timer = ("app/actualize_script.php", 20)
        apps.RECIPES["fake"] = recipe
        wsite = {"domain": "klant.nl", "user": "s_klant_nl", "php": "8.4", "root": str(self.site_root),
                 "docroot": str(self.docroot), "slice": "whp-sites-c7.slice"}
        with mock.patch.object(apps.wordpress, "site_info", lambda d: wsite):
            item = wait(apps.apps_install(self.params(path="rss")))["result"]
            name = f"whp-app-{item['id']}"
            service = (self.systemd / f"{name}.service").read_text()
            self.assertIn("User=s_klant_nl", service)
            self.assertIn("Slice=whp-sites-c7.slice", service)
            self.assertIn(f"ExecStart=/usr/bin/php8.4 {self.docroot}/rss/app/actualize_script.php", service)
            self.assertIn("OnCalendar=*:0/20", (self.systemd / f"{name}.timer").read_text())
            self.assertIn(["systemctl", "enable", "--now", f"{name}.timer"], self.commands)
            wait(apps.apps_remove({"domain": "klant.nl", "id": item["id"]}))
        self.assertFalse((self.systemd / f"{name}.service").exists())

    def test_list_reports_updates_from_the_files_on_disk(self):
        recipe = FakeRecipe()
        recipe.installed_version = lambda target: Path(target, "VERSION").read_text().strip()
        apps.RECIPES["fake"] = recipe
        item = wait(apps.apps_install(self.params()))["result"]
        (self.docroot / "VERSION").write_text("1.3.0\n")  # the app updated itself
        with mock.patch.object(apps, "_load_releases", lambda: {"fake": {"version": "1.10.0"}}):
            row = apps.apps_list({"domain": "klant.nl"})["apps"][0]
        self.assertEqual(row["id"], item["id"])
        self.assertEqual(row["version"], "1.3.0")
        self.assertEqual(row["latest_version"], "1.10.0")
        self.assertTrue(row["update_available"])

    def test_versions_compare_as_numbers(self):
        self.assertTrue(apps.newer("1.10.0", "1.9.9"))
        self.assertTrue(apps.newer("2025-05-14b", "2025-05-14a"))
        self.assertTrue(apps.newer("v31.0.10", "31.0.9"))
        self.assertFalse(apps.newer("6.8", "6.8"))
        self.assertFalse(apps.newer(None, "6.8"))

    def test_maintained(self):
        now = time.time()
        self.assertTrue(apps.maintained({"released": now - 10 * 86400}, 365))
        self.assertFalse(apps.maintained({"released": now - 400 * 86400}, 365))
        self.assertFalse(apps.maintained({"released": now, "eol": True}, 365))
        self.assertIsNone(apps.maintained({}, 365))

    def test_deny_snippets_win_over_static_files(self):
        self.assertEqual(apps.deny_dirs("wiki", ["data"]), "location ^~ /wiki/data/ { return 404; }\n")
        self.assertEqual(apps.deny_dirs("", ["conf"]), "location ^~ /conf/ { return 404; }\n")
        self.assertEqual(apps.deny_files("forum", ["config.php"]), "location = /forum/config.php { return 404; }\n")

    def test_page_cache_is_off_for_apps_with_own_sessions(self):
        for app_id in ["joomla", "nextcloud", "piwigo", "mediawiki", "dokuwiki", "phpbb", "freshrss"]:
            self.assertFalse(getattr(apps.RECIPES[app_id], "page_cache", True), app_id)
        self.assertTrue(getattr(apps.RECIPES["wordpress"], "page_cache", True))

    def test_mediawiki_gets_secrets_through_the_environment(self):
        ctx = apps.Context(self.site, "mediawiki", "wiki", self.params(admin_user="Beheer"), lambda _l: None)
        ctx.db = {"name": "klant_nl_mediawik_ab12", "user": "klant_nl_mediawik_ab12", "password": "db-secret-pw"}
        os.makedirs(ctx.target)
        self.releases = {"mediawiki": {"version": "1.44.2"}}
        with mock.patch.object(apps, "cached_download", lambda *a, **k: "/cache/mw.tar.gz"), \
                mock.patch.object(apps.Context, "extract", lambda self, archive: None):
            apps.MediaWiki().install(ctx)
        body, env = self.wrappers[0]
        self.assertIn('"--scriptpath=/wiki"', body)
        self.assertIn('"--server=https://klant.nl"', body)
        self.assertIn("$WHP_ADMIN_PASS", body)
        self.assertNotIn("a-very-long-secret", body)
        self.assertNotIn("db-secret-pw", body)
        self.assertEqual(env["WHP_DB_PASS"], "db-secret-pw")
        self.assertFalse(any("a-very-long-secret" in a or "db-secret-pw" in a for c in self.commands for a in c))
        self.assertEqual(ctx.version, "1.44.2")

    def test_phpbb_release_date_comes_from_the_download_listing(self):
        pages = {
            apps.PHPBB_VERSIONS: json.dumps({"stable": {"3.0": {"current": "3.0.14", "eol": None},
                                                        "3.3": {"current": "3.3.19", "eol": None}}}),
            apps.PHPBB_RELEASES.format(branch="3.3"):
                '<tr><td><a href="3.3.18/">3.3.18/</a></td><td align="right">2026-05-01 10:00  </td></tr>\n'
                '<tr><td><a href="3.3.19/">3.3.19/</a></td><td align="right">2026-10-20 14:30  </td></tr>\n',
        }
        with mock.patch.object(apps, "fetch_text", lambda url, **kw: pages[url]):
            info = apps.PhpBB().release_info()
        self.assertEqual(info["version"], "3.3.19")
        self.assertEqual(time.strftime("%Y-%m-%d %H:%M", time.gmtime(info["released"])), "2026-10-20 14:30")

    def test_phpbb_config_is_valid_and_escaped(self):
        ctx = apps.Context(self.site, "phpbb", "forum", self.params(title='Mijn "forum"'), lambda _l: None)
        ctx.db = {"name": "klant_nl_phpbb_ab12", "user": "klant_nl_phpbb_ab12", "password": "p\"w:d #x"}
        conf = apps.PhpBB().config(ctx)
        self.assertIn('dbpasswd: "p\\"w:d #x"', conf)
        self.assertIn('name: "Mijn \\"forum\\""', conf)
        self.assertIn('script_path: "/forum"', conf)
        self.assertIn("cookie_secure: true", conf)

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
