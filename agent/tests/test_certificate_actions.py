import os
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from whp_agent.actions import certificates, sites  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

CERT = "-----BEGIN CERTIFICATE-----\nQUJD\n-----END CERTIFICATE-----"
KEY = "-----BEGIN PRIVATE KEY-----\nREVG\n-----END PRIVATE KEY-----"


class CertificateActionsTest(unittest.TestCase):
    def setUp(self):
        patcher = mock.patch.object(certificates, "run")
        self.run = patcher.start()
        self.addCleanup(patcher.stop)
        self.files = {}

        def fake(argv, **kwargs):
            if argv[0] == sites.WHP_SITE and argv[1] == "ssl":
                for opt in ("--custom-cert", "--custom-key", "--custom-chain"):
                    if opt in argv:
                        path = argv[argv.index(opt) + 1]
                        self.files[opt] = (open(path).read(), oct(os.stat(path).st_mode & 0o777))
            return mock.Mock(stdout='{"domain": "a.nl", "ssl": "custom", "cert_file": null, "key_file": null}', returncode=0)
        self.run.side_effect = fake

    def calls(self):
        return [c[0][0] for c in self.run.call_args_list]

    def test_registered(self):
        for name in ["ssl.info", "ssl.list", "ssl.custom", "ssl.wildcard", "ssl.letsencrypt", "ssl.cloudflare"]:
            self.assertIn(name, ACTIONS)

    def test_custom_writes_private_temp_files_and_removes_them(self):
        certificates.ssl_custom({"domain": "A.nl", "certificate": CERT + "\n" + CERT, "private_key": KEY})
        argv = self.calls()[0]
        self.assertEqual(argv[:3], [sites.WHP_SITE, "ssl", "a.nl"])
        self.assertIn("--custom-chain", argv)  # a pasted full chain is split
        self.assertEqual(self.files["--custom-key"], (KEY + "\n", "0o600"))
        self.assertEqual(self.files["--custom-cert"][0].count("BEGIN CERTIFICATE"), 1)
        self.assertFalse(os.path.exists(os.path.dirname(argv[argv.index("--custom-key") + 1])))
        self.assertEqual(self.calls()[1], [sites.WHP_SITE, "cert", "a.nl", "--json"])

    def test_custom_rejects_non_pem(self):
        for params in [{"domain": "a.nl", "certificate": "--wildcard", "private_key": KEY},
                       {"domain": "a.nl", "certificate": CERT, "private_key": CERT},
                       {"domain": "a.nl", "certificate": CERT + "\nrm -rf /", "private_key": KEY},
                       {"domain": "a.nl", "certificate": CERT, "private_key": KEY + "\n" + KEY},
                       {"domain": "a.nl; reboot", "certificate": CERT, "private_key": KEY}]:
            with self.assertRaises(ActionError):
                certificates.ssl_custom(params)
        self.assertEqual(self.calls(), [])

    def test_wildcard_and_letsencrypt(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(certificates, "CLOUDFLARE_INI", d + "/ssl/cf.ini"):
            certificates.ssl_wildcard({"domain": "a.nl", "cloudflare_token": "abcdefghijklmnopqrstuvwxyz_0123"})
            self.assertEqual(oct(os.stat(d + "/ssl/cf.ini").st_mode & 0o777), "0o600")
            self.assertIn("dns_cloudflare_api_token = abcdefghijklmnopqrstuvwxyz_0123", open(d + "/ssl/cf.ini").read())
            with self.assertRaises(ActionError):
                certificates.ssl_cloudflare({"cloudflare_token": "bad token\ndns=x"})
            self.assertEqual(certificates.ssl_cloudflare({"cloudflare_token": ""}), {"stored": False})
            self.assertFalse(os.path.exists(d + "/ssl/cf.ini"))
        self.assertEqual(self.calls()[0], [sites.WHP_SITE, "ssl", "a.nl", "--wildcard", "--json"])
        certificates.ssl_letsencrypt({"domain": "a.nl"})
        self.assertIn([sites.WHP_SITE, "ssl", "a.nl", "--letsencrypt", "--json"], self.calls())

    def test_read_certificate(self):
        self.run.side_effect = lambda argv, **kw: subprocess.run(argv, capture_output=True, text=True)
        with tempfile.TemporaryDirectory() as d:
            subprocess.run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256", "-nodes",
                            "-days", "10", "-subj", "/CN=a.nl", "-addext", "subjectAltName=DNS:a.nl,DNS:*.a.nl",
                            "-keyout", d + "/k.pem", "-out", d + "/c.pem"], check=True, capture_output=True)
            info = certificates.read_certificate(d + "/c.pem")
        self.assertEqual(info["names"], ["a.nl", "*.a.nl"])
        self.assertEqual(info["common_name"], "a.nl")
        self.assertTrue(info["self_signed"])
        self.assertIn(info["days_left"], (9, 10))
        self.assertFalse(info["expired"])
        self.assertIsNone(certificates.read_certificate("/nonexistent"))


if __name__ == "__main__":
    unittest.main()
