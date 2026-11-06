import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

from whp_agent.actions import mail, mail_extras, mailbox  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

HASH = "{ARGON2ID}$argon2id$v=19$m=65536,t=4,p=1$TnUxT0w2bWR1QUFFVWFFZA$5UsgsAp7OaZHAvS1MySZqW0n48jyH8AXdEhs9QRKE/w"


class CatchAllTest(unittest.TestCase):
    def test_catch_all_keeps_mail_of_existing_mailboxes(self):
        plan = mailbox.parse_sync({
            "mailboxes": [{"email": "jan@klant.nl", "password_hash": HASH}, {"email": "piet@klant.nl", "password_hash": HASH},
                          {"email": "kees@ander.nl", "password_hash": HASH}],
            "aliases": [{"address": "@klant.nl", "targets": ["jan@klant.nl"]}, {"address": "info@klant.nl", "targets": ["piet@klant.nl"]}],
        }, {"klant.nl", "ander.nl"})
        aliases = mailbox.render(plan)["virtual_aliases"]
        self.assertIn("@klant.nl jan@klant.nl\n", aliases)
        self.assertIn("jan@klant.nl jan@klant.nl\n", aliases)
        self.assertIn("piet@klant.nl piet@klant.nl\n", aliases)
        self.assertIn("info@klant.nl piet@klant.nl\n", aliases)
        # No catch-all on ander.nl: no identity entries needed there.
        self.assertNotIn("kees@ander.nl", aliases)

    def test_no_identity_entries_without_catch_all(self):
        plan = mailbox.parse_sync({"mailboxes": [{"email": "jan@klant.nl", "password_hash": HASH}], "aliases": []}, {"klant.nl"})
        self.assertNotIn("jan@klant.nl jan@klant.nl", mailbox.render(plan)["virtual_aliases"])


class AutoreplyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        mailbox.VMAIL_ROOT = mail_extras.VMAIL_ROOT = root / "vmail"
        (mail_extras.VMAIL_ROOT / "klant.nl" / "jan").mkdir(parents=True)
        (mail_extras.VMAIL_ROOT / "klant.nl" / "oud").mkdir(parents=True)
        self.calls = []
        self._run = mail_extras.run
        mail_extras.run = lambda argv, timeout=60, check=True, **kw: self.calls.append(argv) or subprocess.CompletedProcess(argv, 0, "", "")

    def tearDown(self):
        mail_extras.run = self._run
        self.tmp.cleanup()

    def entry(self, **kw):
        return {"email": "Jan@klant.nl", "subject": "Out of office", "body": 'Back on "Monday".\r\nC:\\temp', "days": 2,
                "start": "2026-11-10", "end": "2026-11-20", "addresses": ["info@klant.nl"], "from_name": "Jan Jansen", **kw}

    def test_render_escapes_and_checks_dates(self):
        plan = mail_extras.parse_autoreplies({"autoreplies": [self.entry()]})
        script = mail_extras.render_autoreply("jan@klant.nl", plan["jan@klant.nl"])
        self.assertIn('require ["vacation", "date", "relational"];', script)
        self.assertIn('currentdate :value "ge" "date" "2026-11-10"', script)
        self.assertIn('currentdate :value "le" "date" "2026-11-20"', script)
        self.assertIn('not header :is "X-Spam" "Yes"', script)
        self.assertIn(':days 2 :subject "Out of office"', script)
        self.assertIn(':addresses ["info@klant.nl", "jan@klant.nl"]', script)
        self.assertIn('"Back on \\"Monday\\".\nC:\\\\temp"', script)
        self.assertIn(':from "\\"Jan Jansen\\" <jan@klant.nl>"', script)

    def test_no_dates_means_always(self):
        plan = mail_extras.parse_autoreplies({"autoreplies": [self.entry(start=None, end="", from_name=None)]})
        script = mail_extras.render_autoreply("jan@klant.nl", plan["jan@klant.nl"])
        self.assertNotIn("currentdate", script)
        self.assertIn(':from "jan@klant.nl"', script)

    def test_rejects_bad_input(self):
        for bad in ({"subject": ""}, {"subject": "x" * 201}, {"body": ""}, {"days": 0}, {"days": 31}, {"days": True},
                    {"start": "2026-13-01"}, {"start": "2026-11-20", "end": "2026-11-10"}, {"addresses": ["nope"]}, {"email": "@klant.nl"}):
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                mail_extras.parse_autoreplies({"autoreplies": [self.entry(**bad)]})
        with self.assertRaises(ActionError):
            mail_extras.parse_autoreplies({"autoreplies": [self.entry(), self.entry()]})

    def test_subject_newlines_are_flattened(self):
        plan = mail_extras.parse_autoreplies({"autoreplies": [self.entry(subject="Away\r\nBcc: x@evil.test")]})
        self.assertEqual(plan["jan@klant.nl"]["subject"], "Away Bcc: x@evil.test")

    def test_sync_writes_compiles_and_removes(self):
        old = mail_extras.VMAIL_ROOT / "klant.nl" / "oud" / mail_extras.AUTOREPLY_FILE
        old.write_text("old")
        old.with_suffix(".svbin").write_text("bin")
        out = mail_extras.autoreply_sync({"autoreplies": [self.entry(), self.entry(email="weg@klant.nl")]})
        script = mail_extras.VMAIL_ROOT / "klant.nl" / "jan" / mail_extras.AUTOREPLY_FILE
        self.assertTrue(script.exists())
        self.assertEqual(oct(script.stat().st_mode & 0o777), "0o600")
        self.assertEqual(out["written"], ["jan@klant.nl"])
        self.assertEqual(out["removed"], ["oud@klant.nl"])
        self.assertEqual(out["missing_home"], ["weg@klant.nl"])
        self.assertFalse(old.exists())
        self.assertFalse(old.with_suffix(".svbin").exists())
        self.assertEqual(self.calls, [["sievec", str(script)]])
        # Unchanged: not written or compiled again.
        out = mail_extras.autoreply_sync({"autoreplies": [self.entry()]})
        self.assertEqual(out["written"], [])
        self.assertEqual(len(self.calls), 1)

    def test_compile_error_removes_the_script(self):
        mail_extras.run = lambda argv, **kw: subprocess.CompletedProcess(argv, 1, "", "error: bad")
        with self.assertRaises(ActionError):
            mail_extras.autoreply_sync({"autoreplies": [self.entry()]})
        self.assertFalse((mail_extras.VMAIL_ROOT / "klant.nl" / "jan" / mail_extras.AUTOREPLY_FILE).exists())


class ImapImportTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        mail_extras.IMPORT_DIR = root / "imports"
        mail_extras.IMPORT_CONF = root / "mail-import.conf"
        mail_extras.SECRETS_FILE = root / "secrets.env"
        mail_extras.SECRETS_FILE.write_text("OTHER=1\nIMAP_IMPORT_MASTER_PASS='masterpw'\n")
        mail_extras.DOVECOT_USERS = root / "users"
        mail_extras.DOVECOT_USERS.write_text(f"jan@klant.nl:{HASH}::::::\n")
        mail_extras.IMAPSYNC = sys.executable  # any executable
        self.calls = []
        self.active = set()
        self._run = mail_extras.run
        self._resolve = mail_extras.resolve_host

        def fake_run(argv, timeout=60, check=True, **kw):
            self.calls.append(argv)
            if argv[1] == "is-active":
                return subprocess.CompletedProcess(argv, 0 if argv[-1] in self.active else 3, "", "")
            return subprocess.CompletedProcess(argv, 0, "", "")

        mail_extras.run = fake_run
        mail_extras.resolve_host = lambda host: "198.51.100.7"

    def tearDown(self):
        mail_extras.run = self._run
        mail_extras.resolve_host = self._resolve
        self.tmp.cleanup()

    def params(self, **kw):
        return {"id": 7, "email": "jan@klant.nl", "host": "imap.Provider.nl", "port": 993, "security": "ssl",
                "user": "jan@oud.nl", "password": "geheim pw", **kw}

    def test_parse_source(self):
        src = mail_extras.parse_source({"host": "imap.example.org.", "security": "starttls", "user": "a", "password": "b"})
        self.assertEqual(src, {"host": "imap.example.org", "port": 143, "security": "starttls", "user": "a", "password": "b"})
        for bad in ({"host": "-x"}, {"host": "a b"}, {"security": "tls"}, {"port": 0}, {"port": "993"}, {"user": ""},
                    {"password": "a\nb"}, {"host": "a..b"}):
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                mail_extras.parse_source({"host": "imap.example.org", "user": "a", "password": "b", **bad})

    def test_private_hosts_refused_unless_allowed(self):
        mail_extras.resolve_host = self._resolve
        with self.assertRaises(ActionError) as e:
            mail_extras.resolve_host("127.0.0.1")
        self.assertEqual(e.exception.code, "forbidden")
        mail_extras.IMPORT_CONF.write_text("IMAP_IMPORT_ALLOW_PRIVATE=yes\n")
        self.assertEqual(mail_extras.resolve_host("127.0.0.1"), "127.0.0.1")

    def test_argv_uses_master_login_and_password_files(self):
        job = Path("/x/7")
        argv = mail_extras.imapsync_argv(mail_extras.parse_source(self.params()), "jan@klant.nl", job)
        self.assertIn("--ssl1", argv)
        self.assertEqual(argv[argv.index("--authuser2") + 1], "whp-import")
        self.assertEqual(argv[argv.index("--user2") + 1], "jan@klant.nl")
        self.assertEqual(argv[argv.index("--passfile1") + 1], "/x/7/pass1")
        self.assertNotIn("geheim pw", argv)
        self.assertNotIn("--gmail1", argv)
        gmail = mail_extras.imapsync_argv(mail_extras.parse_source(self.params(host="imap.gmail.com", security="none", port=143)), "jan@klant.nl", job)
        self.assertIn("--gmail1", gmail)
        self.assertIn("--notls1", gmail)

    def test_start_writes_private_files_and_runs_a_unit(self):
        out = mail_extras.imap_import_start(self.params())
        self.assertEqual(out["state"], "running")
        job = mail_extras.IMPORT_DIR / "7"
        self.assertEqual((job / "pass1").read_text(), "geheim pw\n")
        self.assertEqual((job / "pass2").read_text(), "masterpw\n")
        self.assertEqual(oct((job / "pass1").stat().st_mode & 0o777), "0o600")
        argv = json.loads((job / "argv.json").read_text())
        self.assertEqual(argv[0], sys.executable)
        unit = self.calls[-1]
        self.assertEqual(unit[0], mail_extras.SYSTEMD_RUN)
        self.assertIn("--unit=whp-imap-import-7.service", unit)
        self.assertIn("--uid=whp", unit)
        self.assertNotIn("geheim pw", " ".join(unit))
        self.assertNotIn("$", mail_extras.RUNNER)
        self.assertNotIn("%", mail_extras.RUNNER)
        compile(mail_extras.RUNNER, "runner", "exec")

    def test_start_checks(self):
        with self.assertRaises(ActionError) as e:
            mail_extras.imap_import_start(self.params(email="piet@klant.nl"))
        self.assertEqual(e.exception.code, "not_found")
        self.active.add("whp-imap-import-7.service")
        with self.assertRaises(ActionError) as e:
            mail_extras.imap_import_start(self.params())
        self.assertEqual(e.exception.code, "conflict")
        mail_extras.SECRETS_FILE.write_text("")
        with self.assertRaises(ActionError):
            mail_extras.imap_import_start(self.params(id=8))

    def test_running_limit(self):
        mail_extras.IMPORT_CONF.write_text("IMAP_IMPORT_MAX_RUNNING=1\n")
        (mail_extras.IMPORT_DIR / "3").mkdir(parents=True)
        self.active.add("whp-imap-import-3.service")
        with self.assertRaises(ActionError) as e:
            mail_extras.imap_import_start(self.params())
        self.assertEqual(e.exception.details, {"busy": True})

    def test_parse_log_progress_and_stats(self):
        head = "Host1 Nb messages:             40 messages\nHost2 Nb messages:   0 messages\n"
        tail = ("msg INBOX/1 {120} copied to INBOX/1  1.2 msgs/s ETA: Fri Nov  6 18:40:00 2026  30 s  39/40 msgs left\n"
                "msg INBOX/2 {120} copied to INBOX/2  1.2 msgs/s ETA: Fri Nov  6 18:40:00 2026  29 s  30/40 msgs left\n")
        p = mail_extras.parse_log(head, tail)
        self.assertEqual((p["total"], p["left"], p["done"], p["percent"], p["finished_stats"]), (40, 30, 10, 25, False))
        tail += "++++ Statistics\nMessages transferred                    : 38 \nMessages skipped                        : 2\nTotal bytes transferred                 : 4560 (4.453 KiB)\nDetected 1 errors\n"
        p = mail_extras.parse_log(head, tail)
        self.assertEqual((p["transferred"], p["skipped"], p["bytes"], p["errors"], p["percent"]), (38, 2, 4560, 1, 100))
        self.assertEqual(mail_extras.parse_log("", "")["percent"], 0)

    def test_status_states(self):
        mail_extras.imap_import_start(self.params())
        job = mail_extras.IMPORT_DIR / "7"
        self.active.add("whp-imap-import-7.service")
        self.assertEqual(mail_extras.imap_import_status({"id": 7})["state"], "running")
        self.assertTrue((job / "pass1").exists())
        self.active.clear()
        self.assertEqual(mail_extras.imap_import_status({"id": 7})["state"], "failed")  # unit gone without an exit code
        self.assertFalse((job / "pass1").exists())
        (job / "exit").write_text("0")
        self.assertEqual(mail_extras.imap_import_status({"id": 7})["state"], "done")
        (job / "exit").write_text("111")
        (job / "log").write_text("Messages transferred : 3\nDetected 2 errors\nERR: quota exceeded\n")
        st = mail_extras.imap_import_status({"id": 7})
        self.assertEqual(st["state"], "partial")
        self.assertEqual(st["error_lines"], ["Detected 2 errors", "ERR: quota exceeded"])
        mail_extras.imap_import_stop({"id": 7})
        self.assertEqual(mail_extras.imap_import_status({"id": 7})["state"], "stopped")
        self.assertEqual(mail_extras.imap_import_status({"id": 99})["state"], "unknown")
        mail_extras.imap_import_forget({"id": 7})
        self.assertFalse(job.exists())


if __name__ == "__main__":
    unittest.main()
