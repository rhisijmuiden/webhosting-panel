import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from whp_agent.actions import web_rules as w  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

HASH = "$6$abcdefghijklmnop$" + "A" * 86


class WebRulesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = self.tmp.name
        for name, value in {"NGINX_WHP": f"{root}/nginx", "SITES_DB": f"{root}/sites",
                            "GEOIP_DB": f"{root}/geo.mmdb", "GEOIP_CONF": f"{root}/geo.conf"}.items():
            p = mock.patch.object(w, name, value)
            p.start()
            self.addCleanup(p.stop)
        os.makedirs(f"{root}/sites")
        os.makedirs(f"{root}/www/public")
        self.site = {"domain": "a.nl", "ssl": "active", "docroot": f"{root}/www/public"}
        p = mock.patch.object(w, "site_info", return_value=self.site)
        p.start()
        self.addCleanup(p.stop)
        self.run = mock.patch.object(w, "run").start()
        self.addCleanup(mock.patch.stopall)
        self.run.return_value = mock.Mock(returncode=0, stdout="10.0.0.5 2001:db8::5", stderr="")

    def calls(self):
        return [c[0][0] for c in self.run.call_args_list]

    def test_registered(self):
        self.assertIn("webrules.apply", ACTIONS)
        self.assertIn("webrules.status", ACTIONS)

    def test_redirects_rendered_most_specific_first(self):
        cfg = w.parse({"domain": "a.nl", "redirects": [
            {"from": "/", "to": "https://b.nl/"},
            {"from": "/blog/", "to": "https://b.nl/news/", "prefix": True, "code": 302},
            {"from": "/old.html", "to": "/new?x=1"},
        ]})
        text = w.render(cfg)
        lines = [line for line in text.splitlines() if line.startswith("if ")]
        self.assertEqual(lines, [
            'if ($uri ~ "^/old\\.html/?$") { return 301 "/new?x=1"; }',
            'if ($uri ~ "^/blog(?:/(.*))?$") { return 302 "https://b.nl/news/$1$is_args$args"; }',
            'if ($uri ~ "^/$") { return 301 "https://b.nl/$is_args$args"; }',
        ])

    def test_query_string_can_be_dropped(self):
        cfg = w.parse({"domain": "a.nl", "redirects": [{"from": "/a", "to": "/b", "keep_query": False}]})
        self.assertIn('return 301 "/b"; }', w.render(cfg))

    def test_rejects_injection(self):
        bad = [
            {"redirects": [{"from": "/a;b", "to": "/c"}]},
            {"redirects": [{"from": "/a", "to": "/c; return 200"}]},
            {"redirects": [{"from": "/a", "to": "https://x.nl/$host"}]},
            {"redirects": [{"from": "/a", "to": "https://x.nl/\"}"}]},
            {"redirects": [{"from": "/a", "to": "//evil.nl"}]},
            {"redirects": [{"from": "/a", "to": "javascript:alert(1)"}]},
            {"redirects": [{"from": "/a", "to": "/a"}]},
            {"redirects": [{"from": "/../etc", "to": "/c"}]},
            {"redirects": [{"from": "/a", "to": "/b", "code": 200}]},
            {"protected": [{"path": "/a", "realm": "x\"; deny all", "users": [{"name": "u", "hash": HASH}]}]},
            {"protected": [{"path": "/a", "users": [{"name": "u:x", "hash": HASH}]}]},
            {"protected": [{"path": "/a", "users": [{"name": "u", "hash": "plain"}]}]},
            {"protected": [{"path": "/a", "users": []}]},
            {"error_pages": {"200": "/x.html"}},
            {"error_pages": {"404": "x.html"}},
            {"access": {"mode": "deny", "networks": ["not-an-ip"]}},
            {"access": {"countries_mode": "deny", "countries": ["NLD"]}},
            {"hsts": {"preload": True}},
        ]
        for extra in bad:
            with self.assertRaises(ActionError, msg=extra):
                w.parse({"domain": "a.nl", **extra})

    def test_protected_folders_longest_path_wins(self):
        cfg = w.parse({"domain": "a.nl", "protected": [
            {"path": "/admin/secret", "users": [{"name": "a", "hash": HASH}]},
            {"path": "/", "realm": "Staging", "users": [{"name": "b", "hash": HASH}]},
        ]})
        text = w.render(cfg)
        root_at = text.index('if ($uri ~ "^/")')
        deep_at = text.index('^/admin/secret(?:/|$)')
        self.assertLess(root_at, deep_at)
        self.assertIn("auth_basic $whp_auth;", text)
        self.assertIn('set $whp_auth "Staging"', text)

    def test_access_modes(self):
        cfg = w.parse({"domain": "a.nl", "access": {"mode": "allow", "networks": ["1.2.3.4", "10.0.0.0/8"]}})
        cfg["server_ips"] = ["10.0.0.5"]
        text = w.render(cfg)
        self.assertIn("allow 127.0.0.1;\nallow ::1;\nallow 10.0.0.5;\nallow 1.2.3.4/32;\nallow 10.0.0.0/8;\ndeny all;", text)
        cfg = w.parse({"domain": "a.nl", "access": {"mode": "deny", "networks": ["1.2.3.4"]}})
        self.assertIn("deny 1.2.3.4/32;", w.render(cfg))
        self.assertNotIn("deny all", w.render(cfg))

    def test_country_allow_lets_unknown_and_acme_through(self):
        cfg = w.parse({"domain": "a.nl", "access": {"countries_mode": "allow", "countries": ["nl", "BE"]}})
        text = w.render(cfg)
        self.assertIn('if ($whp_country !~ "^(BE|NL)$") { set $whp_geo_block 1; }', text)
        self.assertIn('if ($whp_country = "") { set $whp_geo_block ""; }', text)
        self.assertIn("acme-challenge", text)
        self.assertIn("if ($whp_geo_block) { return 403; }", text)

    def test_country_needs_geoip(self):
        with self.assertRaises(ActionError) as e:
            w.apply({"domain": "a.nl", "access": {"countries_mode": "deny", "countries": ["CN"]}})
        self.assertEqual(e.exception.code, "conflict")

    def test_hsts(self):
        default = w.parse({"domain": "a.nl"})
        self.assertIsNone(w.render_hsts(default))
        off = w.parse({"domain": "a.nl", "hsts": {"enabled": False}})
        self.assertIn('"on:a.nl" "";', w.render_hsts(off))
        full = w.parse({"domain": "a.nl", "hsts": {"max_age": 63072000, "subdomains": True, "preload": True}})
        self.assertIn('"on:a.nl" "max-age=63072000; includeSubDomains; preload";', w.render_hsts(full))

    def test_apply_writes_files_and_reloads(self):
        res = w.apply({"domain": "a.nl", "redirects": [{"from": "/a", "to": "/b"}],
                       "protected": [{"path": "/p", "users": [{"name": "u", "hash": HASH}]}],
                       "error_pages": {"404": "/404.html"}, "hsts": {"max_age": 600}})
        self.assertTrue(os.path.isfile(w.snippet_path("a.nl")))
        self.assertTrue(os.path.isfile(w.hsts_path("a.nl")))
        with open(os.path.join(w.auth_dir("a.nl"), "1.htpasswd")) as fh:
            self.assertEqual(fh.read(), f"u:{HASH}\n")
        self.assertEqual(self.calls(), [["nginx", "-t"], ["systemctl", "reload", "nginx"]])
        self.assertEqual(res["missing_error_pages"], [{"code": 404, "path": "/404.html"}])
        self.assertTrue(res["https_active"])

    def test_empty_rules_remove_files(self):
        w.apply({"domain": "a.nl", "redirects": [{"from": "/a", "to": "/b"}], "hsts": {"enabled": False}})
        w.apply({"domain": "a.nl"})
        self.assertFalse(os.path.exists(w.snippet_path("a.nl")))
        self.assertFalse(os.path.exists(w.hsts_path("a.nl")))

    def test_force_https_switch_calls_whp_site(self):
        w.apply({"domain": "a.nl", "force_https": False})
        self.assertIn([w.WHP_SITE, "https", "a.nl", "allow", "--json"], self.calls())
        open(w.http_allowed_flag("a.nl"), "w").close()
        self.run.reset_mock()
        w.apply({"domain": "a.nl", "force_https": True})
        self.assertIn([w.WHP_SITE, "https", "a.nl", "force", "--json"], self.calls())

    def test_nginx_failure_restores_previous_rules(self):
        w.apply({"domain": "a.nl", "redirects": [{"from": "/a", "to": "/b"}]})
        with open(w.snippet_path("a.nl")) as fh:
            before = fh.read()
        self.run.side_effect = lambda argv, **kw: mock.Mock(returncode=1 if argv == ["nginx", "-t"] else 0, stdout="", stderr="bad")
        with self.assertRaises(ActionError):
            w.apply({"domain": "a.nl", "redirects": [{"from": "/x", "to": "/y"}],
                     "protected": [{"path": "/p", "users": [{"name": "u", "hash": HASH}]}]})
        with open(w.snippet_path("a.nl")) as fh:
            self.assertEqual(fh.read(), before)
        self.assertFalse(os.path.exists(w.auth_dir("a.nl")))

    def test_status(self):
        data = w.status({"domain": "a.nl"})
        self.assertFalse(data["geoip"]["available"])
        self.assertTrue(data["force_https"])
        open(w.GEOIP_DB, "w").close()
        open(w.GEOIP_CONF, "w").close()
        self.assertTrue(w.status({})["geoip"]["available"])


if __name__ == "__main__":
    unittest.main()
