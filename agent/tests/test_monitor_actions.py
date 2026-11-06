import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

load_all()
from whp_agent.actions import monitor  # noqa: E402


class MonitorActionsTest(unittest.TestCase):
    def test_registered(self):
        self.assertIn("monitor.check", ACTIONS)

    def test_parse_x509(self):
        out = monitor.parse_x509("notAfter=Dec  4 10:00:00 2026 GMT\nissuer=C = US, O = Let's Encrypt, CN = R11\n")
        self.assertEqual(out["issuer"], "Let's Encrypt")
        self.assertEqual(out["expires_at"], 1796378400)

    def test_certificate_days_left_and_host_match(self):
        def fake_run(argv, **kw):
            if "-checkhost" in argv:
                return mock.Mock(stdout="Hostname klant.nl does match certificate\n", returncode=0)
            return mock.Mock(stdout="notAfter=Dec  4 10:00:00 2026 GMT\nissuer=O = Let's Encrypt, CN = R11\n", returncode=0)
        with mock.patch.object(monitor, "served_certificate", return_value="PEM"), mock.patch.object(monitor, "run", side_effect=fake_run):
            res = monitor.check_certificate("klant.nl", now=1796378400 - 10 * 86400)
        self.assertTrue(res["ok"])
        self.assertEqual(res["days_left"], 10)
        self.assertTrue(res["matches"])

    def test_certificate_for_another_domain_is_not_ok(self):
        def fake_run(argv, **kw):
            if "-checkhost" in argv:
                return mock.Mock(stdout="Hostname klant.nl does NOT match certificate\n", returncode=1)
            return mock.Mock(stdout="notAfter=Dec  4 10:00:00 2026 GMT\n", returncode=0)
        with mock.patch.object(monitor, "served_certificate", return_value="PEM"), mock.patch.object(monitor, "run", side_effect=fake_run):
            res = monitor.check_certificate("klant.nl", now=1700000000)
        self.assertFalse(res["ok"])
        self.assertFalse(res["matches"])

    def test_certificate_connection_error(self):
        with mock.patch.object(monitor, "served_certificate", side_effect=ConnectionRefusedError("refused")):
            res = monitor.check_certificate("klant.nl")
        self.assertFalse(res["ok"])
        self.assertIn("refused", res["error"])

    def test_disks_skip_virtual_and_duplicates(self):
        with tempfile.NamedTemporaryFile("w", delete=False) as fh:
            fh.write("/dev/vda1 / ext4 rw 0 0\ntmpfs /run tmpfs rw 0 0\n/dev/vda1 /var/www ext4 rw 0 0\nproc /proc proc rw 0 0\n")
        st = mock.Mock(f_blocks=1000, f_frsize=4096, f_bavail=100, f_bfree=150, f_files=100, f_ffree=40)
        try:
            with mock.patch.object(monitor.os, "statvfs", return_value=st):
                res = monitor.disks(fh.name)
        finally:
            os.unlink(fh.name)
        self.assertEqual([d["mount"] for d in res], ["/"])
        self.assertEqual(res[0]["pct"], round(850 * 100 / 950, 1))
        self.assertEqual(res[0]["inodes_pct"], 60.0)

    def test_memory(self):
        with tempfile.NamedTemporaryFile("w", delete=False) as fh:
            fh.write("MemTotal: 1000 kB\nMemAvailable: 250 kB\nSwapTotal: 100 kB\nSwapFree: 100 kB\n")
        try:
            res = monitor.memory(fh.name)
        finally:
            os.unlink(fh.name)
        self.assertEqual(res["pct"], 75.0)
        self.assertEqual(res["swap_pct"], 0.0)

    def test_mail_queue(self):
        with mock.patch.object(monitor, "run", return_value=mock.Mock(stdout='{"queue_id":"A"}\n{"queue_id":"B"}\n', returncode=0)):
            self.assertEqual(monitor.mail_queue(), 2)
        with mock.patch.object(monitor, "run", side_effect=ActionError("command_failed", "Command not found: postqueue")):
            self.assertIsNone(monitor.mail_queue())

    def test_check_validates_and_combines(self):
        with mock.patch.object(monitor, "check_site", side_effect=lambda d, s, timeout=15: {"domain": d, "ok": True, "code": 200, "ms": 40, "error": None}), \
                mock.patch.object(monitor, "check_certificate", return_value={"ok": True, "days_left": 60}), \
                mock.patch.object(monitor, "service_states", return_value=[]), \
                mock.patch.object(monitor, "mail_queue", return_value=0):
            res = monitor.check({"sites": [{"domain": "a.nl", "ssl": True}, {"domain": "b.nl", "ssl": False}]})
        self.assertEqual(res["sites"][0]["ssl"]["days_left"], 60)
        self.assertIsNone(res["sites"][1]["ssl"])
        with self.assertRaises(ActionError):
            monitor.check({"sites": [{"domain": "not a domain"}]})


if __name__ == "__main__":
    unittest.main()
