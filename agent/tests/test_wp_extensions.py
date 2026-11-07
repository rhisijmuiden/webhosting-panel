"""Plugins and themes: activate/deactivate, delete, auto-updates, search and install
from WordPress.org. WP-CLI is faked with a small in-memory WordPress."""

import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.insert(0, str(HERE))

from test_wordpress_actions import FakeWp, WordPressTest  # noqa: E402
from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import wp_extensions as wpe  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


class StatefulWp(FakeWp):
    """WP-CLI with state: plugin/theme lists change when commands run."""

    def __init__(self):
        super().__init__()
        self.plugins = {
            "akismet": {"title": "Akismet", "status": "active", "version": "5.3", "auto_update": "off"},
            "hello": {"title": "Hello", "status": "inactive", "version": "1.7", "auto_update": "off"},
        }
        self.themes = {
            "child": {"title": "Child", "status": "active", "version": "1.0", "auto_update": "off"},
            "parent": {"title": "Parent", "status": "parent", "version": "2.0", "auto_update": "off"},
            "twentytwentyfour": {"title": "TT4", "status": "inactive", "version": "1.2", "auto_update": "off"},
        }
        self.fail = set()

    def __call__(self, argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
        res = super().__call__(argv, timeout, check, input_text, cwd, env)
        if wordpress.WP_CLI in argv:
            args = argv[argv.index(wordpress.WP_CLI) + 3:]
            target = args[2] if len(args) > 2 else ""
            if f"{args[0]} {args[1]} {target}".strip() in self.fail or (args[1:2] == ["install"] and target in self.fail):
                return SimpleNamespace(stdout="", stderr=f"Error: could not {args[1]} {target}.", returncode=1)
        return res

    def answer(self, args):
        kind, cmd = args[0], args[1] if len(args) > 1 else ""
        store = self.plugins if kind == "plugin" else self.themes if kind == "theme" else None
        if store is None:
            return super().answer(args)
        names = [a for a in args[2:] if not a.startswith("--")]
        if cmd == "list":
            return json.dumps([{"name": n, "update": "none", "update_version": "", **d} for n, d in store.items()])
        if cmd == "activate":
            if kind == "theme":
                for d in store.values():
                    if d["status"] == "active":
                        d["status"] = "inactive"
            for n in names:
                store[n]["status"] = "active"
        elif cmd == "deactivate":
            for n in names:
                store[n]["status"] = "inactive"
        elif cmd == "delete":
            for n in names:
                store.pop(n, None)
        elif cmd == "auto-updates":
            for n in names[1:]:
                store[n]["auto_update"] = "on" if names[0] == "enable" else "off"
        elif cmd == "install":
            store[names[0]] = {"title": names[0].title(), "status": "inactive", "version": "1.0", "auto_update": "off"}
        elif cmd == "search":
            return ("Success: Showing 2 of 30 plugins.\n" + json.dumps([
                {"name": "Yoast SEO", "slug": "wordpress-seo", "version": "23.8", "author": "<a href=\"x\">Team Yoast</a>",
                 "rating": 96, "num_ratings": 27000, "active_installs": 10000000, "short_description": "<b>Improve</b> SEO",
                 "requires": "6.5", "tested": "6.7", "requires_php": "7.4", "icons": {"1x": "https://ps.w.org/i.png"}},
                {"name": "Bad", "slug": "../evil"},
            ]))
        return ""


class WpExtensionsTest(WordPressTest):
    def setUp(self):
        super().setUp()
        self.fake = StatefulWp()
        for target in (wordpress, wpe.toolkit):
            p = mock.patch.object(target, "run", self.fake)
            p.start()
            self.patches.append(p)
        p = mock.patch.object(wpe, "run", self.fake)
        p.start()
        self.patches.append(p)

    def apply(self, kind, op, slugs):
        return wpe.wp_ext_apply({"domain": "klant.nl", "kind": kind, "op": op, "slugs": slugs})

    def curls(self):
        return [c for c in self.fake.calls if c["argv"][0] == "curl"]

    def test_list_skips_the_update_check_and_drops_dropins(self):
        out = wpe.wp_ext_list({"domain": "klant.nl"})
        self.assertEqual(["akismet", "hello"], [p["name"] for p in out["plugins"]])
        self.assertEqual("parent", out["themes"][1]["status"])
        for args in self.fake.wp_args():
            self.assertIn("--skip-update-check", args)

    def test_activate_checks_the_site_and_purges_the_cache(self):
        self.fake.health = [200, 200]
        out = self.apply("plugin", "activate", ["hello"])
        self.assertEqual(["hello"], out["done"])
        self.assertFalse(out["rolled_back"])
        self.assertEqual({"before": 200, "after": 200}, out["health"])
        self.assertEqual("active", next(p for p in out["plugins"] if p["name"] == "hello")["status"])
        self.assertTrue(any(c["argv"][:3] == [wpe.toolkit.WHP_SITE, "cache", "klant.nl"] for c in self.fake.calls))
        # The plugin itself is never loaded by the toolkit's own WP-CLI calls.
        self.assertIn("--skip-plugins", self.fake.wp_calls()[-1]["argv"])

    def test_activation_that_breaks_the_site_is_undone(self):
        self.fake.health = [200, 500, 200]
        out = self.apply("plugin", "activate", ["hello"])
        self.assertTrue(out["rolled_back"])
        self.assertEqual([], out["done"])
        self.assertIn("HTTP 500", out["errors"][0]["error"])
        self.assertEqual("inactive", self.fake.plugins["hello"]["status"])
        self.assertEqual(200, out["health"]["after_rollback"])

    def test_broken_theme_switch_puts_the_previous_theme_back(self):
        self.fake.health = [200, 503, 200]
        out = self.apply("theme", "activate", ["twentytwentyfour"])
        self.assertTrue(out["rolled_back"])
        self.assertEqual("active", self.fake.themes["child"]["status"])
        self.assertEqual("inactive", self.fake.themes["twentytwentyfour"]["status"])

    def test_no_rollback_when_the_site_was_already_broken(self):
        self.fake.health = [500, 500]
        out = self.apply("plugin", "activate", ["hello"])
        self.assertFalse(out["rolled_back"])
        self.assertEqual(["hello"], out["done"])

    def test_delete_refuses_active_plugins_and_the_active_or_parent_theme(self):
        out = self.apply("plugin", "delete", ["akismet", "hello", "missing"])
        self.assertEqual(["hello"], out["done"])
        self.assertEqual({"akismet", "missing"}, {e["slug"] for e in out["errors"]})
        self.assertNotIn("hello", self.fake.plugins)
        self.assertIn("akismet", self.fake.plugins)
        out = self.apply("theme", "delete", ["child", "parent", "twentytwentyfour"])
        self.assertEqual(["twentytwentyfour"], out["done"])
        self.assertEqual({"child", "parent"}, set(self.fake.themes))
        self.assertEqual([], self.curls())

    def test_auto_updates_per_plugin(self):
        out = self.apply("plugin", "auto_update_on", ["akismet"])
        self.assertTrue(next(p for p in out["plugins"] if p["name"] == "akismet")["auto_update"])
        self.assertIn(["plugin", "auto-updates", "enable", "akismet", "--skip-plugins", "--skip-themes"], self.fake.wp_args())
        out = self.apply("plugin", "auto_update_off", ["akismet"])
        self.assertFalse(out["plugins"][0]["auto_update"])

    def test_one_failure_does_not_stop_the_rest(self):
        self.fake.fail = {"plugin deactivate akismet"}
        self.fake.plugins["hello"]["status"] = "active"
        out = self.apply("plugin", "deactivate", ["akismet", "hello"])
        self.assertEqual(["hello"], out["done"])
        self.assertIn("could not deactivate akismet", out["errors"][0]["error"])

    def test_validation(self):
        for params in [
            {"kind": "plugin", "op": "activate", "slugs": ["../x"]},
            {"kind": "plugin", "op": "activate", "slugs": []},
            {"kind": "theme", "op": "deactivate", "slugs": ["child"]},
            {"kind": "plugin", "op": "uninstall", "slugs": ["hello"]},
            {"kind": "mu", "op": "activate", "slugs": ["hello"]},
            {"kind": "theme", "op": "activate", "slugs": ["parent", "twentytwentyfour"]},
        ]:
            with self.subTest(params=params), self.assertRaises(ActionError):
                wpe.wp_ext_apply({"domain": "klant.nl", **params})

    def test_search_cleans_up_wordpress_org_results(self):
        out = wpe.wp_ext_search({"domain": "klant.nl", "kind": "plugin", "query": "seo"})
        self.assertEqual(30, out["total"])
        self.assertEqual(2, out["pages"])
        self.assertEqual(1, len(out["results"]))
        r = out["results"][0]
        self.assertEqual(("wordpress-seo", "Team Yoast", "Improve SEO", "https://ps.w.org/i.png"),
                         (r["slug"], r["author"], r["description"], r["image"]))
        args = self.fake.wp_args()[-1]
        self.assertEqual(["plugin", "search", "seo", "--per-page=24", "--page=1"], args[:5])
        with self.assertRaises(ActionError):
            wpe.wp_ext_search({"domain": "klant.nl", "kind": "plugin", "query": "a"})

    def test_install_by_slug_and_activate(self):
        self.fake.health = [200, 200]
        out = wpe.wp_ext_install({"domain": "klant.nl", "kind": "plugin", "slug": "wordpress-seo", "activate": True})
        self.assertTrue(out["activated"])
        self.assertEqual("active", self.fake.plugins["wordpress-seo"]["status"])
        self.assertIn(["plugin", "install", "wordpress-seo", "--skip-plugins", "--skip-themes"], self.fake.wp_args())
        with self.assertRaises(ActionError) as e:
            wpe.wp_ext_install({"domain": "klant.nl", "kind": "plugin", "slug": "wordpress-seo"})
        self.assertEqual("conflict", e.exception.code)
        for bad in ["https://evil.test/x.zip", "/tmp/x.zip", "a b"]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                wpe.wp_ext_install({"domain": "klant.nl", "kind": "plugin", "slug": bad})

    def test_failed_install_reports_the_reason(self):
        self.fake.fail = {"nope"}
        with self.assertRaises(ActionError) as e:
            wpe.wp_ext_install({"domain": "klant.nl", "kind": "theme", "slug": "nope"})
        self.assertIn("could not install nope", e.exception.message)


# The base class' own tests run in test_wordpress_actions.py already.
for _name in [n for n in dir(WordPressTest) if n.startswith("test_")]:
    if not hasattr(WpExtensionsTest, _name) or getattr(WpExtensionsTest, _name) is getattr(WordPressTest, _name):
        setattr(WpExtensionsTest, _name, None)
del WordPressTest

if __name__ == "__main__":
    unittest.main()
