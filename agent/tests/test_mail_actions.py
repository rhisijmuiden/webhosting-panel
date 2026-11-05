import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

# Until the phase 2 core lands, whp_agent is a namespace package spread over
# agent/ and tests/stubs/, so the stubs fill in for registry, validate, run and jobs.
from whp_agent.actions import mail  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


class RoutingTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        mail.POSTFIX_DOMAINS = root / "postfix/virtual_domains"
        mail.SNAPPYMAIL_DOMAINS = root / "snappymail/domains"
        mail.SNAPPYMAIL_DOMAINS.mkdir(parents=True)
        mail.STATE_FILE = root / "state/domains.json"
        mail.DKIM_DIR = root / "dkim"
        self._run = mail.run
        mail.run = lambda argv, timeout=30: None

    def tearDown(self):
        mail.run = self._run
        self.tmp.cleanup()

    def params(self, **extra):
        return {
            "mail_host": "mail.rhis.nl",
            "domains": [
                {"domain": "klant.nl", "provider": "rhis", "mta_sts_mode": "testing"},
                {"domain": "bedrijf.nl", "provider": "microsoft365", "mta_sts_mode": "enforce"},
                {"domain": "elders.nl", "provider": "external"},
            ],
            **extra,
        }

    def test_only_rhis_domains_are_local(self):
        out = mail.set_routing(self.params())
        self.assertEqual(out["local_domains"], ["klant.nl"])
        text = mail.POSTFIX_DOMAINS.read_text()
        self.assertIn("klant.nl OK", text)
        self.assertNotIn("bedrijf.nl", text)
        self.assertTrue((mail.SNAPPYMAIL_DOMAINS / "klant.nl.json").exists())
        self.assertFalse((mail.SNAPPYMAIL_DOMAINS / "bedrijf.nl.json").exists())
        state = json.loads(mail.STATE_FILE.read_text())
        self.assertEqual(state["domains"]["bedrijf.nl"]["mta_sts"]["mx"], ["*.mail.protection.outlook.com"])
        self.assertNotIn("mta_sts", state["domains"]["elders.nl"])

    def test_switch_to_m365_removes_webmail_but_keeps_foreign_files(self):
        mail.set_routing(self.params())
        (mail.SNAPPYMAIL_DOMAINS / "handmatig.nl.json").write_text("{}")
        p = self.params()
        p["domains"][0]["provider"] = "microsoft365"
        out = mail.set_routing(p)
        self.assertIn("webmail-removed:klant.nl", out["changed"])
        self.assertTrue((mail.SNAPPYMAIL_DOMAINS / "handmatig.nl.json").exists())
        self.assertEqual(out["local_domains"], [])

    def test_second_run_changes_nothing(self):
        mail.set_routing(self.params())
        self.assertEqual(mail.set_routing(self.params())["changed"], [])

    def test_validation(self):
        with self.assertRaises(ActionError) as e:
            mail.set_routing({"mail_host": "mail.rhis.nl", "domains": [{"domain": "x;rm -rf /", "provider": "rhis"}]})
        self.assertEqual(e.exception.code, "invalid_params")
        with self.assertRaises(ActionError):
            mail.set_routing({"mail_host": "mail.rhis.nl", "domains": [{"domain": "a.nl", "provider": "gmail"}]})
        with self.assertRaises(ActionError):
            mail.set_routing({"mail_host": "not a host", "domains": []})

    def test_endpoint_names(self):
        plan = mail.parse_routing(self.params())
        self.assertEqual(mail.endpoint_names("klant.nl", plan["domains"]["klant.nl"]),
                         ["autoconfig.klant.nl", "autodiscover.klant.nl", "mta-sts.klant.nl"])
        self.assertEqual(mail.endpoint_names("bedrijf.nl", plan["domains"]["bedrijf.nl"]), ["mta-sts.bedrijf.nl"])
        self.assertEqual(mail.endpoint_names("elders.nl", plan["domains"]["elders.nl"]), [])

    def test_dkim_generate_refuses_to_overwrite(self):
        mail.DKIM_DIR.mkdir()
        (mail.DKIM_DIR / "klant.nl.whp.key").write_text("x")
        with self.assertRaises(ActionError) as e:
            mail.dkim_generate({"domain": "klant.nl"})
        self.assertEqual(e.exception.code, "conflict")

    @unittest.skipUnless(os.path.exists("/usr/bin/openssl"), "needs openssl")
    def test_dkim_generate_with_real_openssl(self):
        import subprocess
        mail.run = lambda argv, timeout=30: subprocess.run(argv, check=True, capture_output=True, timeout=timeout)
        out = mail.dkim_generate({"domain": "klant.nl"})
        self.assertEqual(out["selector"], "whp")
        self.assertTrue(out["public_key"].startswith("MIIBIjAN"))
        self.assertEqual(mail.dkim_get({"domain": "klant.nl"})["public_key"], out["public_key"])


if __name__ == "__main__":
    unittest.main()
