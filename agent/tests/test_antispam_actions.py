import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

from whp_agent.actions import antispam  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

HDR = (
    "From: =?UTF-8?Q?Pi=C3=ABt?= <piet@spam.example>\r\n"
    "To: jan@klant.nl\r\n"
    "Subject: Win a prize\r\n"
    "X-Spam: Yes\r\n"
    "X-Spam-Level: ************\r\n"
    "X-Spamd-Result: default: False [12.10 / 15.00]; CLAM_VIRUS(0.00)\r\n\r\n"
)


class SpamPolicyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        antispam.SIEVE_DIR = root / "sieve"
        antispam.POLICY_FILE = root / "spam-policy.json"
        antispam.SECRETS_FILE = root / "secrets.env"
        antispam.DOVECOT_USERS = root / "users"
        antispam.DOVECOT_USERS.write_text(
            "jan@klant.nl:{ARGON2ID}x::::::\n"
            "oud@klant.nl:{ARGON2ID}x::::::nologin=y reason=Mailbox_disabled\n"
            "piet@ander.nl:{ARGON2ID}x::::::\n"
        )
        self.calls = []
        self.outputs = {}
        self._run = antispam.run
        antispam.run = self.fake_run

    def tearDown(self):
        antispam.run = self._run
        self.tmp.cleanup()

    def fake_run(self, argv, timeout=60, check=True, input_text=None, **kw):
        self.calls.append(argv)
        out = next((v for k, v in self.outputs.items() if k in " ".join(argv)), "")
        return subprocess.CompletedProcess(argv, 0, out, "")

    def policy(self):
        return {
            "default_quarantine_score": 10,
            "domains": [
                {"domain": "Klant.nl", "quarantine_score": 8, "allow": ["boss@partner.nl", "@trusted.example"], "block": ["spam.example"]},
                {"domain": "ander.nl", "quarantine_score": None, "junk": False},
            ],
        }

    def test_parse_and_render(self):
        plan = antispam.parse_policy(self.policy())
        self.assertEqual(plan["domains"]["klant.nl"]["allow"], {"addresses": ["boss@partner.nl"], "domains": ["trusted.example"]})
        script = antispam.render_sieve(plan)
        self.assertIn('if envelope :domain :is "to" "ander.nl" {', script)
        self.assertIn('elsif envelope :domain :is "to" "klant.nl" {', script)
        self.assertIn('anyof (address :all :is "from" ["boss@partner.nl"], address :domain :is "from" ["trusted.example"])', script)
        self.assertIn('header :regex "X-Spam-Level" "^\\\\*{8,}"', script)
        self.assertIn('header :regex "X-Spam-Level" "^\\\\*{10,}"', script)  # fallback for unknown domains
        # ander.nl: no quarantine and no Junk rule at all
        ander = script.split('"ander.nl" {', 1)[1].split("}", 1)[0]
        self.assertNotIn("fileinto", ander)

    def test_render_without_domains(self):
        script = antispam.render_sieve(antispam.parse_policy({"domains": []}))
        self.assertNotIn("else", script)
        self.assertTrue(script.rstrip().endswith("}"))

    def test_rejects_bad_input(self):
        for bad in (
            {"domains": "x"},
            {"domains": [{"domain": "klant.nl", "quarantine_score": 3}]},
            {"domains": [{"domain": "klant.nl", "allow": ["not an address@"]}]},
            {"domains": [{"domain": "klant.nl"}, {"domain": "KLANT.nl"}]},
            {"domains": [{"domain": 'x".nl'}]},
        ):
            with self.assertRaises(ActionError, msg=bad):
                antispam.parse_policy(bad)

    def test_sync_writes_and_compiles(self):
        r = antispam.spam_policy_sync(self.policy())
        self.assertTrue(r["changed"])
        self.assertEqual(self.calls, [["sievec", str(antispam.SIEVE_DIR / "before.sieve")]])
        self.assertIn("klant.nl", json.loads(antispam.POLICY_FILE.read_text())["domains"])
        (antispam.SIEVE_DIR / "before.svbin").write_text("bin")
        self.calls.clear()
        self.assertFalse(antispam.spam_policy_sync(self.policy())["changed"])
        self.assertEqual(self.calls, [])

    def test_parse_fetch(self):
        rows = antispam.parse_fetch("jan@klant.nl", json.dumps([{"uid": "7", "guid": "g", "date.received": "2026-11-06 10:00:00", "size.physical": "1234", "hdr": HDR}]))
        self.assertEqual(rows[0]["from"], "Piët <piet@spam.example>")
        self.assertEqual(rows[0]["score"], 12)
        self.assertTrue(rows[0]["virus"])
        self.assertEqual(antispam.parse_fetch("x", "not json"), [])

    def test_list_skips_disabled_mailboxes(self):
        self.outputs = {"fetch": json.dumps([{"uid": "7", "hdr": HDR, "date.received": "2026-11-06"}])}
        r = antispam.quarantine_list({"domain": "klant.nl"})
        self.assertEqual([m["user"] for m in r["messages"]], ["jan@klant.nl"])
        self.assertEqual(self.calls[0][:6], ["doveadm", "-f", "json", "fetch", "-u", "jan@klant.nl"])

    def test_release_learns_and_moves(self):
        antispam.SECRETS_FILE.write_text("RSPAMD_CONTROLLER_PASS='geheim'\n")
        self.outputs = {"text": json.dumps([{"text": "raw message"}])}
        r = antispam.quarantine_release({"domain": "klant.nl", "user": "jan@klant.nl", "uid": 7})
        self.assertTrue(r["learned"])
        self.assertIn(["rspamc", "-h", "127.0.0.1:11334", "-P", "geheim", "learn_ham"], self.calls)
        self.assertEqual(self.calls[-1], ["doveadm", "move", "-u", "jan@klant.nl", "INBOX", "mailbox", "Quarantine", "uid", "7"])

    def test_cannot_touch_other_domains(self):
        with self.assertRaises(ActionError):
            antispam.quarantine_release({"domain": "klant.nl", "user": "piet@ander.nl", "uid": 1})
        with self.assertRaises(ActionError):
            antispam.quarantine_delete({"domain": "klant.nl", "user": "jan@klant.nl", "uids": ["1; rm"]})

    def test_delete(self):
        r = antispam.quarantine_delete({"domain": "klant.nl", "user": "jan@klant.nl", "uids": [3, 4]})
        self.assertEqual(r["deleted"], 2)
        self.assertEqual(self.calls[-1], ["doveadm", "expunge", "-u", "jan@klant.nl", "mailbox", "Quarantine", "uid", "3,4"])


class TlsaTest(unittest.TestCase):
    def test_reuse_key(self):
        tmp = tempfile.TemporaryDirectory()
        antispam.LETSENCRYPT_RENEWAL = Path(tmp.name)
        (Path(tmp.name) / "mail.rhis.nl.conf").write_text("[renewalparams]\nreuse_key = True\n")
        (Path(tmp.name) / "web.rhis.nl.conf").write_text("[renewalparams]\nkey_type = ecdsa\n")
        self.assertTrue(antispam.reuse_key("/etc/letsencrypt/live/mail.rhis.nl/fullchain.pem"))
        self.assertFalse(antispam.reuse_key("/etc/letsencrypt/live/web.rhis.nl/fullchain.pem"))
        self.assertIsNone(antispam.reuse_key("/etc/ssl/certs/ssl-cert-snakeoil.pem"))
        tmp.cleanup()

    def test_spki_hash_matches_openssl(self):
        tmp = tempfile.TemporaryDirectory()
        key, crt = Path(tmp.name) / "k.pem", Path(tmp.name) / "c.pem"
        try:
            subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256", "-nodes",
                            "-keyout", str(key), "-out", str(crt), "-subj", "/CN=mail.test", "-days", "1"],
                           check=True, capture_output=True)
        except (OSError, subprocess.CalledProcessError):
            self.skipTest("openssl not available")
        pub = subprocess.run(["openssl", "x509", "-noout", "-pubkey", "-in", str(crt)], capture_output=True, text=True, check=True).stdout
        der = subprocess.run(["openssl", "pkey", "-pubin", "-outform", "DER"], input=pub.encode(), capture_output=True, check=True).stdout
        import hashlib
        self.assertEqual(antispam.spki_sha256(crt.read_text()), hashlib.sha256(der).hexdigest())
        tmp.cleanup()



class ShippedDefaultTest(unittest.TestCase):
    def test_default_script_in_repo_matches_renderer(self):
        shipped = (HERE.parent.parent / "mail/antispam/before.sieve").read_text()
        self.assertEqual(shipped, antispam.render_sieve(antispam.parse_policy({"domains": []})))


if __name__ == "__main__":
    unittest.main()
