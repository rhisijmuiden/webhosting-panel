import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

from whp_agent.actions import mail, mailbox  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

HASH = "{ARGON2ID}$argon2id$v=19$m=65536,t=4,p=1$TnUxT0w2bWR1QUFFVWFFZA$5UsgsAp7OaZHAvS1MySZqW0n48jyH8AXdEhs9QRKE/w"


class MailboxSyncTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        mail.STATE_FILE = mailbox.STATE_FILE = root / "state.json"
        mailbox.STATE_FILE.write_text(json.dumps({"domains": {"klant.nl": {"provider": "rhis"}, "bedrijf.nl": {"provider": "microsoft365"}}}))
        mailbox.DOVECOT_USERS = root / "dovecot/users"
        mailbox.POSTFIX_DIR = root / "postfix"
        mailbox.VMAIL_ROOT = root / "vmail"
        mailbox.VMAIL_ROOT.mkdir()
        mailbox.TRASH = mailbox.VMAIL_ROOT / ".deleted"
        self.calls = []
        self._run = mailbox.run
        mailbox.run = lambda argv, timeout=60, check=True, **kw: self.calls.append(argv) or subprocess.CompletedProcess(argv, 0, "", "")

    def tearDown(self):
        mailbox.run = self._run
        self.tmp.cleanup()

    def params(self):
        return {
            "mailboxes": [
                {"email": "Jan@klant.nl", "password_hash": HASH, "quota_mb": 2048, "send_as": ["info@klant.nl"]},
                {"email": "oud@klant.nl", "password_hash": HASH, "enabled": False},
                {"email": "piet@bedrijf.nl", "password_hash": HASH},
            ],
            "aliases": [
                {"address": "info@klant.nl", "targets": ["jan@klant.nl", "extern@gmail.com"]},
                {"address": "@klant.nl", "targets": ["jan@klant.nl"]},
            ],
        }

    def test_sync_writes_all_maps(self):
        out = mailbox.mailboxes_sync(self.params())
        users = mailbox.DOVECOT_USERS.read_text()
        self.assertIn(f"jan@klant.nl:{HASH}::::::userdb_quota_rule=*:storage=2048M\n", users)
        self.assertIn("oud@klant.nl:" + HASH + "::::::nologin=y reason=Mailbox_disabled\n", users)
        self.assertNotIn("bedrijf.nl", users)
        self.assertEqual(out["skipped_not_local"], ["piet@bedrijf.nl"])
        self.assertIn("jan@klant.nl klant.nl/jan/\n", (mailbox.POSTFIX_DIR / "virtual_mailboxes").read_text())
        aliases = (mailbox.POSTFIX_DIR / "virtual_aliases").read_text()
        self.assertIn("info@klant.nl extern@gmail.com,jan@klant.nl\n", aliases)
        self.assertIn("@klant.nl jan@klant.nl\n", aliases)
        sender = (mailbox.POSTFIX_DIR / "sender_login").read_text()
        self.assertIn("jan@klant.nl jan@klant.nl\n", sender)
        self.assertIn("info@klant.nl jan@klant.nl\n", sender)
        self.assertTrue((mailbox.VMAIL_ROOT / "klant.nl/jan").is_dir())
        self.assertEqual(oct((mailbox.VMAIL_ROOT / "klant.nl/jan").stat().st_mode & 0o777), "0o700")
        self.assertIn(["postfix", "reload"], self.calls)

    def test_second_sync_is_a_no_op(self):
        mailbox.mailboxes_sync(self.params())
        self.calls.clear()
        out = mailbox.mailboxes_sync(self.params())
        self.assertEqual(out["changed"], [])
        self.assertEqual(out["created_homes"], [])
        self.assertEqual(self.calls, [])

    def test_rejects_plain_passwords_and_bad_input(self):
        for bad in [
            {"mailboxes": [{"email": "a@klant.nl", "password_hash": "geheim"}]},
            {"mailboxes": [{"email": "a@klant.nl:x", "password_hash": HASH}]},
            {"mailboxes": [{"email": "a@klant.nl", "password_hash": HASH + "\nroot:x"}]},
            {"mailboxes": [{"email": "a@klant.nl", "password_hash": HASH, "quota_mb": -1}]},
            {"mailboxes": [], "aliases": [{"address": "x@klant.nl", "targets": []}]},
        ]:
            with self.assertRaises(ActionError, msg=bad):
                mailbox.mailboxes_sync(bad)

    def test_alias_cannot_shadow_mailbox(self):
        p = {"mailboxes": [{"email": "jan@klant.nl", "password_hash": HASH}], "aliases": [{"address": "jan@klant.nl", "targets": ["x@klant.nl"]}]}
        with self.assertRaises(ActionError) as e:
            mailbox.mailboxes_sync(p)
        self.assertEqual(e.exception.code, "conflict")

    def test_purge_only_after_removal(self):
        mailbox.mailboxes_sync(self.params())
        with self.assertRaises(ActionError) as e:
            mailbox.mailbox_purge({"email": "jan@klant.nl"})
        self.assertEqual(e.exception.code, "conflict")
        mailbox.mailboxes_sync({"mailboxes": [], "aliases": []})
        out = mailbox.mailbox_purge({"email": "jan@klant.nl"})
        self.assertFalse((mailbox.VMAIL_ROOT / "klant.nl/jan").exists())
        self.assertTrue(Path(out["moved_to"]).is_dir())


if __name__ == "__main__":
    unittest.main()
