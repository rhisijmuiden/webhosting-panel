import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent.actions import security  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def docs(text):
    """Our YAML is JSON documents separated by ---, after a comment line."""
    body = "\n".join(line for line in text.splitlines() if not line.startswith("#"))
    return [json.loads(part) for part in body.split("\n---\n") if part.strip()]


class ParseConfigTest(unittest.TestCase):
    def test_defaults_for_every_service(self):
        cfg = security.parse_config({})
        self.assertEqual(set(cfg["services"]), {"ssh", "mail", "panel", "wordpress"})
        self.assertTrue(all(s["enabled"] for s in cfg["services"].values()))
        self.assertEqual(cfg["services"]["ssh"]["temp_after"], 5)

    def test_rejects_bad_input(self):
        bad = [
            {"services": {"ftp": {}}},
            {"services": {"ssh": {"temp_after": 10, "perm_after": 5}}},
            {"services": {"ssh": {"temp_after": 0}}},
            {"services": {"ssh": {"ban_minutes": "60"}}},
            {"allowlist": ["1.2.3.4; rm -rf /"]},
            {"allowlist": ["10.0.0.0/8"]},  # too wide
            {"sites": [{"domain": "a.nl'] || true || ['"}]},
            {"sites": [{"domain": "a.nl"}, {"domain": "A.nl"}]},
            {"sites": [{"domain": "a.nl", "allowlist": ["x"]}]},
        ]
        for params in bad:
            with self.subTest(params=params), self.assertRaises(ActionError):
                security.parse_config(params)

    def test_normalises_addresses(self):
        cfg = security.parse_config({
            "allowlist": [{"address": "203.0.113.9", "comment": "office"}, "2001:db8::/48"],
            "sites": [{"domain": "Klant.NL", "allowlist": ["198.51.100.4", {"address": "198.51.100.4"}]}],
        })
        self.assertEqual(cfg["allowlist"][0], {"address": "203.0.113.9/32", "comment": "office"})
        self.assertEqual(cfg["sites"][0]["domain"], "klant.nl")
        self.assertEqual(cfg["sites"][0]["allowlist"], ["198.51.100.4/32"])


class RenderTest(unittest.TestCase):
    def cfg(self, **kw):
        params = {
            "services": {
                "ssh": {"temp_after": 3, "window_minutes": 5, "ban_minutes": 120, "perm_after": 20, "perm_window_hours": 24},
                "mail": {"enabled": False},
                "panel": {"perm_after": 0},
            },
            "sites": [
                {"domain": "shop.nl", "wordpress": False, "allowlist": ["198.51.100.4"]},
                {"domain": "off.nl", "enabled": False},
                {"domain": "plain.nl"},
            ],
            "allowlist": [{"address": "203.0.113.0/24", "comment": "office"}],
        }
        params.update(kw)
        return security.parse_config(params)

    def test_scenarios_follow_thresholds(self):
        scenarios = {d["name"]: d for d in docs(security.render_scenarios(self.cfg()))}
        self.assertEqual(set(scenarios), {"whp/ssh-bf", "whp/ssh-bf-perm", "whp/panel-bf", "whp/wordpress-bf", "whp/wordpress-bf-perm"})
        ssh = scenarios["whp/ssh-bf"]
        self.assertEqual(ssh["type"], "leaky")
        self.assertEqual(ssh["capacity"], 2)  # overflows on the 3rd failure
        self.assertEqual(ssh["leakspeed"], "100s")  # 5 minutes / 3
        self.assertEqual(ssh["groupby"], "evt.Meta.source_ip")
        self.assertTrue(ssh["labels"]["remediation"])
        perm = scenarios["whp/ssh-bf-perm"]
        self.assertEqual(perm["capacity"], 19)
        self.assertEqual(perm["leakspeed"], "4320s")
        self.assertIn("24 hours", perm["description"])
        # WordPress protection switched off for one site only.
        self.assertIn("in ['shop.nl']", scenarios["whp/wordpress-bf"]["filter"])
        self.assertIn("wp-login.php", scenarios["whp/wordpress-bf"]["filter"])

    def test_single_failure_is_a_trigger(self):
        out = docs(security.render_scenarios(security.parse_config({"services": {"ssh": {"temp_after": 1, "perm_after": 0}}})))
        ssh = next(d for d in out if d["name"] == "whp/ssh-bf")
        self.assertEqual(ssh["type"], "trigger")
        self.assertNotIn("capacity", ssh)

    def test_everything_off(self):
        cfg = security.parse_config({"services": {s: {"enabled": False} for s in security.SERVICES}})
        self.assertEqual(docs(security.render_scenarios(cfg)), [])
        profiles = docs(security.render_profiles(cfg))
        self.assertEqual([p["name"] for p in profiles], ["default_ip_remediation", "default_range_remediation"])

    def test_profiles_set_ban_durations_before_the_defaults(self):
        profiles = docs(security.render_profiles(self.cfg()))
        names = [p["name"] for p in profiles]
        self.assertEqual(names, ["whp_ssh_temporary", "whp_panel_temporary", "whp_wordpress_temporary", "whp_permanent",
                                 "default_ip_remediation", "default_range_remediation"])
        self.assertEqual(profiles[0]["decisions"], [{"type": "ban", "duration": "120m"}])
        self.assertIn('"whp/ssh-bf"', profiles[0]["filters"][0])
        self.assertEqual(profiles[3]["decisions"][0]["duration"], "87600h")
        self.assertIn('"whp/ssh-bf-perm"', profiles[3]["filters"][0])
        self.assertNotIn("panel-bf-perm", profiles[3]["filters"][0])
        days = docs(security.render_profiles(self.cfg(permanent_days=30)))
        self.assertEqual(days[3]["decisions"][0]["duration"], "720h")

    def test_allowlists(self):
        wl = docs(security.render_allowlists(self.cfg()))[0]["whitelist"]
        self.assertEqual(wl["cidr"], ["203.0.113.0/24"])
        off, shop = wl["expression"]
        self.assertTrue(off.endswith("in ['off.nl']"))
        self.assertIn("IpInRange(evt.Meta.source_ip, #)", shop)
        self.assertIn("['198.51.100.4/32']", shop)
        self.assertIn("== 'shop.nl'", shop)
        self.assertIn("endsWith '@shop.nl'", shop)
        empty = security.render_allowlists(security.parse_config({}))
        self.assertEqual(docs(empty), [])

    def test_static_files(self):
        files = security.render_all(self.cfg())
        names = {str(p.relative_to(security.CROWDSEC_DIR)) for p in files}
        self.assertIn("acquis.d/whp-panel-auth.yaml", names)
        self.assertIn("postoverflows/s01-whitelist/whp-hub-overrides.yaml", names)
        site = docs(files[security.CROWDSEC_DIR / "parsers/s02-enrich/whp-site.yaml"])[0]
        self.assertNotIn("onsuccess", site)  # must not skip the other enrich parsers
        hub = docs(files[security.CROWDSEC_DIR / "postoverflows/s01-whitelist/whp-hub-overrides.yaml"])[0]
        self.assertIn("crowdsecurity/ssh-bf", hub["whitelist"]["expression"][0])
        parser = docs(files[security.CROWDSEC_DIR / "parsers/s01-parse/whp-panel-auth.yaml"])[0]
        self.assertEqual(parser["filter"], "evt.Parsed.program == 'whp-panel'")


class ApplyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.saved = (security.CROWDSEC_DIR, security.STATE_FILE, security.PANEL_AUTH_LOG, security.CSCLI, security.run)
        security.CROWDSEC_DIR = root / "crowdsec"
        security.STATE_FILE = root / "state" / "security.json"
        security.PANEL_AUTH_LOG = str(root / "log" / "auth.log")
        cscli = root / "cscli"
        cscli.write_text("")
        security.CSCLI = str(cscli)
        (security.CROWDSEC_DIR).mkdir()
        (security.CROWDSEC_DIR / "profiles.yaml").write_text("name: default_ip_remediation\n")
        self.calls = []
        self.fail_test = False
        security.run = self.fake_run

    def tearDown(self):
        security.CROWDSEC_DIR, security.STATE_FILE, security.PANEL_AUTH_LOG, security.CSCLI, security.run = self.saved
        self.tmp.cleanup()

    def fake_run(self, argv, timeout=60, check=True, **kw):
        self.calls.append(argv)
        code = 1 if self.fail_test and argv[-1] == "-t" else 0
        return subprocess.CompletedProcess(argv, code, "", "bad scenario" if code else "")

    def test_apply_writes_tests_and_reloads(self):
        out = security.apply({"allowlist": ["203.0.113.9"]})
        self.assertIn(str(security.CROWDSEC_DIR / "scenarios/whp-login.yaml"), out["changed"])
        self.assertTrue((security.CROWDSEC_DIR / "profiles.yaml.whp-orig").exists())
        self.assertTrue(Path(security.PANEL_AUTH_LOG).exists())
        flat = [" ".join(c) for c in self.calls]
        self.assertTrue(any(c.endswith("-t") for c in flat))
        self.assertIn("systemctl reload-or-restart crowdsec", flat)
        self.assertTrue(any("allowlists add whp-panel 203.0.113.9/32" in c for c in flat))
        self.assertTrue(any("decisions delete --ip 203.0.113.9" in c for c in flat))

        # Same settings again: nothing written, no reload, allowlist untouched.
        self.calls.clear()
        out = security.apply({"allowlist": ["203.0.113.9"]})
        self.assertEqual(out["changed"], [])
        self.assertEqual(self.calls, [])

    def test_rejected_config_is_rolled_back(self):
        security.apply({})
        before = (security.CROWDSEC_DIR / "scenarios/whp-login.yaml").read_text()
        self.fail_test = True
        with self.assertRaises(ActionError) as ctx:
            security.apply({"services": {"ssh": {"temp_after": 9}}})
        self.assertEqual(ctx.exception.code, "command_failed")
        self.assertEqual((security.CROWDSEC_DIR / "scenarios/whp-login.yaml").read_text(), before)

    def test_first_apply_rejected_removes_new_files(self):
        self.fail_test = True
        with self.assertRaises(ActionError):
            security.apply({})
        self.assertFalse((security.CROWDSEC_DIR / "scenarios/whp-login.yaml").exists())
        self.assertEqual((security.CROWDSEC_DIR / "profiles.yaml").read_text(), "name: default_ip_remediation\n")


class ActivityTest(unittest.TestCase):
    ALERTS = [
        {"id": 1, "scenario": "whp/wordpress-bf", "events_count": 5, "created_at": "2026-11-06T07:00:00Z",
         "source": {"ip": "203.0.113.7", "cn": "NL"},
         "meta": [{"key": "whp_site", "value": "[\"shop.nl\"]"}],
         "decisions": [{"id": 11, "type": "ban", "duration": "54m"}]},
        {"id": 2, "scenario": "whp/mail-bf", "events_count": 10, "created_at": "2026-11-06T08:00:00Z",
         "source": {"ip": "203.0.113.8"},
         "meta": [{"key": "whp_user", "value": "[\"Info@Shop.nl\"]"}], "decisions": []},
        {"id": 3, "scenario": "whp/ssh-bf", "events_count": 5, "created_at": "2026-11-06T09:00:00Z",
         "source": {"ip": "203.0.113.7"}, "meta": [], "decisions": [{"id": 12, "type": "ban"}]},
        {"id": 4, "scenario": "whp/wordpress-bf", "source": {"ip": "203.0.113.9"},
         "events": [{"meta": [{"key": "whp_site", "value": "other.nl"}]}], "decisions": []},
    ]

    def setUp(self):
        self.saved = security.run
        self.calls = []
        security.run = self.fake_run

    def tearDown(self):
        security.run = self.saved

    def fake_run(self, argv, timeout=60, check=True, **kw):
        self.calls.append(argv)
        out = json.dumps(self.ALERTS) if "alerts" in argv else ""
        return subprocess.CompletedProcess(argv, 0, out, "")

    def test_site_activity_only_shows_own_site_and_mailboxes(self):
        out = security.site_activity({"domain": "shop.nl"})
        self.assertEqual([r["scenario"] for r in out["alerts"]], ["whp/mail-bf", "whp/wordpress-bf"])
        self.assertEqual(out["alerts"][1]["blocked_for"], "54m")

    def test_release_only_lifts_blocks_caused_by_the_site(self):
        out = security.site_release({"domain": "shop.nl", "address": "203.0.113.7"})
        self.assertEqual(out["released"], 1)
        deletes = [c for c in self.calls if "delete" in c]
        self.assertEqual(deletes, [[security.CSCLI, "decisions", "delete", "--id", "11"]])
        self.assertEqual(security.site_release({"domain": "shop.nl", "address": "203.0.113.0/24"})["released"], 0)


if __name__ == "__main__":
    unittest.main()
