import os
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import ftp  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

HASH = "$6$abcdefgh12345678$" + "A" * 86


def account(**over):
    data = {"login": "jan@a.nl", "domain": "a.nl", "password_hash": HASH, "folder": "public", "read_only": False}
    data.update(over)
    return data


class FtpActionsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.etc = os.path.join(self.tmp.name, "etc")
        self.vhosts = os.path.join(self.tmp.name, "vhosts")
        os.makedirs(os.path.join(self.vhosts, "a.nl", "public"))
        os.makedirs(os.path.join(self.vhosts, "b.nl", "public"))
        self.sites = {
            "a.nl": {"domain": "a.nl", "user": "s_a_nl", "suspended": False},
            "b.nl": {"domain": "b.nl", "user": "s_b_nl", "suspended": True},
        }
        for target, value in [("FTP_DIR", self.etc), ("VHOSTS", self.vhosts)]:
            p = mock.patch.object(ftp, target, value)
            p.start()
            self.addCleanup(p.stop)
        patches = [
            mock.patch.object(ftp, "site_info", side_effect=self.site_info),
            mock.patch.object(ftp.pwd, "getpwnam", side_effect=lambda u: SimpleNamespace(pw_uid=2001, pw_gid=2001)),
            mock.patch.object(ftp.grp, "getgrnam", return_value=SimpleNamespace(gr_gid=990)),
            mock.patch.object(ftp.os, "chown"),
            mock.patch.object(ftp, "run"),
        ]
        self.mocks = [p.start() for p in patches]
        for p in patches:
            self.addCleanup(p.stop)
        self.run = self.mocks[-1]

    def site_info(self, domain):
        if domain not in self.sites:
            raise ActionError("not_found", "Unknown website")
        return self.sites[domain]

    def read(self, name):
        with open(os.path.join(self.etc, name)) as fh:
            return fh.read()

    def test_registered(self):
        self.assertIn("ftp.sync", ACTIONS)
        self.assertIn("ftp.status", ACTIONS)

    def test_sync_writes_passwd_and_group(self):
        res = ftp.ftp_sync({"accounts": [
            account(),
            account(login="lees@a.nl", folder="public/wp-content/uploads", read_only=True),
            account(login="all@a.nl", folder=""),
        ]})
        self.assertEqual(res["accounts"], 3)
        self.assertEqual(res["read_only"], 1)
        passwd = self.read("ftpd.passwd").splitlines()
        root = os.path.realpath(os.path.join(self.vhosts, "a.nl"))
        self.assertEqual(passwd[0], f"jan@a.nl:{HASH}:2001:2001::{root}/public:/usr/sbin/nologin")
        self.assertTrue(passwd[1].endswith(f"::{root}/public/wp-content/uploads:/usr/sbin/nologin"))
        self.assertTrue(passwd[2].endswith(f"::{root}:/usr/sbin/nologin"))
        self.assertTrue(os.path.isdir(os.path.join(root, "public", "wp-content", "uploads")))
        self.assertEqual(self.read("ftpd.group"), "whp-ftp-ro:x:990:lees@a.nl\n")
        self.assertEqual(os.stat(os.path.join(self.etc, "ftpd.passwd")).st_mode & 0o777, 0o600)

    def test_suspended_and_unknown_sites_are_left_out(self):
        res = ftp.ftp_sync({"accounts": [account(), account(login="x@b.nl", domain="b.nl"), account(login="y@c.nl", domain="c.nl")]})
        self.assertEqual(res["accounts"], 1)
        self.assertEqual([s["reason"] for s in res["skipped"]], ["suspended", "unknown_website"])
        self.assertNotIn("b.nl", self.read("ftpd.passwd"))

    def test_removed_accounts_are_kicked(self):
        with mock.patch.object(ftp.os.path, "exists", side_effect=lambda p: True if p == ftp.FTPDCTL else os.path.lexists(p)):
            ftp.ftp_sync({"accounts": [account(), account(login="weg@a.nl")]})
            self.run.reset_mock()
            ftp.ftp_sync({"accounts": [account()]})
        argv = self.run.call_args[0][0]
        self.assertEqual(argv[-3:], ["kick", "user", "weg@a.nl"])

    def test_symlink_outside_is_refused(self):
        os.symlink("/etc", os.path.join(self.vhosts, "a.nl", "public", "evil"))
        res = ftp.ftp_sync({"accounts": [account(folder="public/evil")]})
        self.assertEqual(res["accounts"], 0)
        self.assertEqual(res["skipped"][0]["reason"], "folder")

    def test_rejects_bad_input(self):
        bad = [
            account(login="jan@b.nl"),
            account(login="Jan@a.nl"),
            account(login="a.nl"),
            account(login="x:y@a.nl"),
            account(password_hash="plaintext"),
            account(password_hash=HASH + ":0:0"),
            account(folder="../b.nl"),
            account(folder="public/../../etc"),
            account(domain="a.nl; reboot"),
        ]
        for acc in bad:
            with self.assertRaises(ActionError, msg=str(acc)):
                ftp.ftp_sync({"accounts": [acc]})
        with self.assertRaises(ActionError):
            ftp.ftp_sync({"accounts": [account(), account()]})

    def test_not_installed(self):
        self.mocks[2].side_effect = KeyError("whp-ftp-ro")
        with self.assertRaises(ActionError) as e:
            ftp.ftp_sync({"accounts": []})
        self.assertEqual(e.exception.code, "command_failed")

    def test_status(self):
        self.run.return_value = mock.Mock(returncode=0, stdout="256 SHA256:abc root@host (ED25519)\n")
        with mock.patch.object(ftp.paths, "INSTALLER_ENV", os.path.join(self.tmp.name, "installer.env")):
            with open(ftp.paths.INSTALLER_ENV, "w") as fh:
                fh.write("WHP_SFTP_PORT=2022\n")
            data = ftp.ftp_status({})
        self.assertTrue(data["installed"])
        self.assertEqual(data["ftp_port"], 21)
        self.assertEqual(data["sftp_port"], 2022)
        self.assertEqual(data["passive_ports"], [49152, 49407])


if __name__ == "__main__":
    unittest.main()
