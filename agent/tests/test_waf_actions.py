import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent.actions import waf  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


def audit_line(uid="klant.nl~abc123", engine="Enabled", code=403, messages=None, **req):
    if messages is None:
        messages = [
            {"message": "SQL Injection Attack Detected via libinjection",
             "details": {"ruleId": "942100", "data": "Matched Data: s&sos found within ARGS:id: 1' or '1'='1",
                         "severity": "2", "tags": ["application-multi", "attack-sqli", "OWASP_CRS"]}},
            {"message": "Inbound Anomaly Score Exceeded (Total Score: 5)",
             "details": {"ruleId": "949110", "data": "", "severity": "0", "tags": ["anomaly-evaluation"]}},
        ]
    return json.dumps({"transaction": {
        "client_ip": "203.0.113.7", "time_stamp": "Sat Nov  7 02:10:00 2026", "unique_id": uid,
        "request": {"method": "GET", "uri": "/?id=1'%20or%20'1'='1", "http_version": 1.1,
                    "headers": {"Host": "www.klant.nl", "User-Agent": "sqlmap/1.8", "Cookie": "secret=1", **req}},
        "response": {"http_code": code, "headers": {}},
        "producer": {"modsecurity": "ModSecurity v3.0.14", "secrules_engine": engine},
        "messages": messages,
    }}) + "\n"


class WafTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.sites = self.tmp / "sites"
        self.waf_dir = self.tmp / "waf"
        self.http = self.tmp / "conf.d" / "whp-waf.conf"
        self.log = self.tmp / "audit.log"
        for d in (self.sites / "klant.nl", self.sites / "shop.nl", self.waf_dir / "sites", self.http.parent):
            d.mkdir(parents=True)
        (self.waf_dir / "main.conf").write_text("# rules\n")
        self.http.write_text("modsecurity off;\n")
        patches = {
            "NGINX_SITES": self.sites, "WAF_DIR": self.waf_dir, "HTTP_CONF": self.http,
            "AUDIT_LOG": self.log, "LOCK_FILE": self.tmp / "waf.lock", "RELOAD": False,
        }
        for k, val in patches.items():
            p = mock.patch.object(waf, k, val)
            p.start()
            self.addCleanup(p.stop)


class ParseConfigTest(WafTestCase):
    def test_defaults(self):
        cfg = waf.parse_config({})
        self.assertEqual((cfg["paranoia"], cfg["threshold"], cfg["sites"]), (1, 5, []))

    def test_rejects_bad_input(self):
        bad = [
            {"paranoia": 5},
            {"threshold": 1},
            {"sites": [{"domain": "klant.nl", "mode": "maybe"}]},
            {"sites": [{"domain": "klant.nl", "mode": "block"}, {"domain": "KLANT.nl", "mode": "off"}]},
            {"sites": [{"domain": "../etc", "mode": "block"}]},
            {"exceptions": [{"rule": "942100"}]},
            {"exceptions": [{"rule": 942100, "path": "/wp-admin\" \"id:1,deny"}]},
            {"exceptions": [{"rule": 942100, "path": "no-slash"}]},
            {"exceptions": [{"rule": 942100, "param": "a;b"}]},
            {"exceptions": [{"rule": 942100, "param": "a b"}]},
            {"exceptions": [{"rule": 1}] * 201},
        ]
        for params in bad:
            with self.subTest(params=params), self.assertRaises(ActionError):
                waf.parse_config(params)

    def test_dedupes_exceptions(self):
        cfg = waf.parse_config({"exceptions": [{"rule": 942100}, {"rule": 942100, "path": None}]})
        self.assertEqual(cfg["exceptions"], [{"rule": 942100, "path": None, "param": None}])


class RenderTest(WafTestCase):
    def test_exception_kinds(self):
        lines = waf.render_exceptions([
            {"rule": 942100, "path": None, "param": None},
            {"rule": 941100, "path": None, "param": "content"},
            {"rule": 932100, "path": "/wp-admin/", "param": None},
            {"rule": 942200, "path": "/api/", "param": "json.query"},
        ], 10000)
        self.assertEqual(lines[0], "SecRuleRemoveById 942100")
        self.assertEqual(lines[1], 'SecRuleUpdateTargetById 941100 "!ARGS:content"')
        self.assertEqual(lines[2], 'SecRule REQUEST_FILENAME "@beginsWith /wp-admin/" "id:10002,phase:1,pass,t:none,nolog,ctl:ruleRemoveById=932100"')
        self.assertIn("id:10003,", lines[3])
        self.assertIn("ctl:ruleRemoveTargetById=942200;ARGS:json.query", lines[3])

    def test_server_settings(self):
        text = waf.render_server(waf.parse_config({"paranoia": 2, "threshold": 7}))
        self.assertIn("tx.blocking_paranoia_level=2", text)
        self.assertIn("tx.inbound_anomaly_score_threshold=7", text)


class ApplyTest(WafTestCase):
    def test_writes_per_site_files_and_cleans_up(self):
        # A site that was protected before and is now off or gone loses its files.
        (self.waf_dir / "sites" / "oud.nl.conf").write_text("x")
        (self.sites / "oud.nl").mkdir()
        (self.sites / "oud.nl" / "20-waf.conf").write_text("x")
        (self.sites / "shop.nl" / "20-waf.conf").write_text("x")
        (self.sites / "shop.nl" / "10-php-limits.conf").write_text("keep")

        result = waf.apply({
            "paranoia": 1, "threshold": 5, "exceptions": [{"rule": 920350}],
            "sites": [
                {"domain": "klant.nl", "mode": "block", "exceptions": [{"rule": 942100, "path": "/wp-admin/"}]},
                {"domain": "shop.nl", "mode": "off"},
                {"domain": "nieuw.nl", "mode": "detect"},
            ],
        })
        self.assertEqual(result["missing"], ["nieuw.nl"])
        self.assertEqual((result["block"], result["detect"], result["off"]), (1, 1, 1))
        inc = (self.sites / "klant.nl" / "20-waf.conf").read_text()
        self.assertIn("modsecurity on;", inc)
        self.assertIn('modsecurity_transaction_id "klant.nl~$request_id";', inc)
        rules = (self.waf_dir / "sites" / "klant.nl.conf").read_text()
        self.assertIn("SecRuleEngine On", rules)
        self.assertIn("ctl:ruleRemoveById=942100", rules)
        self.assertIn("SecRuleRemoveById 920350", (self.waf_dir / "server-exceptions.conf").read_text())
        self.assertFalse((self.sites / "shop.nl" / "20-waf.conf").exists())
        self.assertTrue((self.sites / "shop.nl" / "10-php-limits.conf").exists())
        self.assertFalse((self.sites / "oud.nl" / "20-waf.conf").exists())
        self.assertFalse((self.waf_dir / "sites" / "oud.nl.conf").exists())

        st = waf.status({})
        self.assertTrue(st["available"])
        self.assertEqual(st["sites"], {"klant.nl": "block"})

    def test_detect_mode(self):
        waf.apply({"sites": [{"domain": "klant.nl", "mode": "detect"}]})
        self.assertIn("SecRuleEngine DetectionOnly", (self.waf_dir / "sites" / "klant.nl.conf").read_text())
        self.assertEqual(waf.status({})["sites"], {"klant.nl": "detect"})

    def test_rolls_back_when_nginx_refuses(self):
        waf.apply({"sites": [{"domain": "klant.nl", "mode": "detect"}]})
        before = (self.waf_dir / "sites" / "klant.nl.conf").read_text()
        with mock.patch.object(waf, "_reload", side_effect=ActionError("config_rejected", "no")):
            with self.assertRaises(ActionError):
                waf.apply({"sites": [{"domain": "klant.nl", "mode": "block"}, {"domain": "shop.nl", "mode": "block"}]})
        self.assertEqual((self.waf_dir / "sites" / "klant.nl.conf").read_text(), before)
        self.assertFalse((self.sites / "shop.nl" / "20-waf.conf").exists())

    def test_unavailable(self):
        self.http.unlink()
        with self.assertRaises(ActionError) as ctx:
            waf.apply({})
        self.assertEqual(ctx.exception.code, "not_found")
        self.assertFalse(waf.status({})["available"])


class EventsTest(WafTestCase):
    def test_parses_a_blocked_request(self):
        ev = waf.parse_event(audit_line())
        self.assertEqual(ev["domain"], "klant.nl")
        self.assertEqual(ev["id"], "abc123")
        self.assertTrue(ev["blocked"])
        self.assertEqual(ev["score"], 5)
        self.assertEqual(ev["host"], "www.klant.nl")
        self.assertEqual([r["id"] for r in ev["rules"]], [942100])
        self.assertEqual(ev["rules"][0]["tags"], ["attack-sqli"])
        self.assertNotIn("secret", json.dumps(ev))  # cookies never leave the server

    def test_detect_only_is_not_blocked(self):
        ev = waf.parse_event(audit_line(engine="DetectionOnly", code=200))
        self.assertFalse(ev["blocked"])
        self.assertEqual(ev["mode"], "detect")

    def test_skips_foreign_and_empty_lines(self):
        self.assertIsNone(waf.parse_event("not json"))
        self.assertIsNone(waf.parse_event(audit_line(uid="1699999.123")))
        self.assertIsNone(waf.parse_event(audit_line(messages=[])))

    def test_cursor_reads_every_line_once(self):
        first = waf.events({})
        self.assertEqual(first["cursor"]["offset"], 0)  # no log yet
        self.log.write_text(audit_line(uid="klant.nl~a"))
        start = waf.events({})  # first call starts at the end
        self.assertEqual(start["events"], [])
        with open(self.log, "a") as fh:
            fh.write(audit_line(uid="klant.nl~b") + audit_line(uid="shop.nl~c"))
            fh.write(audit_line(uid="klant.nl~d")[:40])  # still being written
        got = waf.events({"cursor": start["cursor"]})
        self.assertEqual([e["id"] for e in got["events"]], ["b", "c"])
        again = waf.events({"cursor": got["cursor"]})
        self.assertEqual(again["events"], [])

    def test_follows_copytruncate_rotation(self):
        self.log.write_text(audit_line(uid="klant.nl~a"))
        cur = waf.events({"cursor": None, "from_start": True})["cursor"]
        got = waf.events({"cursor": cur})
        self.assertEqual([e["id"] for e in got["events"]], ["a"])
        with open(self.log, "a") as fh:
            fh.write(audit_line(uid="klant.nl~b"))
        rotated = self.log.with_name("audit.log.1")
        rotated.write_text(self.log.read_text())
        with open(self.log, "w"):  # truncate in place, same inode
            pass
        got2 = waf.events({"cursor": got["cursor"]})
        self.assertEqual([e["id"] for e in got2["events"]], ["b"])
        self.assertEqual(got2["cursor"]["offset"], 0)

    def test_limit(self):
        self.log.write_text("".join(audit_line(uid=f"klant.nl~{i}") for i in range(5)))
        cur = {"inode": os.stat(self.log).st_ino, "offset": 0}
        got = waf.events({"cursor": cur, "limit": 2})
        self.assertEqual(len(got["events"]), 2)
        self.assertTrue(got["more"])
        rest = waf.events({"cursor": got["cursor"], "limit": 10})
        self.assertEqual([e["id"] for e in rest["events"]], ["2", "3", "4"])


if __name__ == "__main__":
    unittest.main()
