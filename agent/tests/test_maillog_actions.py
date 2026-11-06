import io
import json
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

from whp_agent.actions import maillog  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

QID = "4Xk9Lz1mQwzB3c"


def entry(identifier, message, n):
    return json.dumps({
        "__CURSOR": f"s=abc;i={n}", "__REALTIME_TIMESTAMP": str(1_762_420_000_000_000 + n * 1_000_000),
        "SYSLOG_IDENTIFIER": identifier, "MESSAGE": message,
    })


class ParseLineTest(unittest.TestCase):
    def test_authenticated_client(self):
        e = maillog.parse_line("postfix/submission/smtpd",
                               f"{QID}: client=unknown[203.0.113.5]:51234, sasl_method=PLAIN, sasl_username=jan@klant.nl")
        self.assertEqual(e["kind"], "client")
        self.assertEqual(e["service"], "submission")
        self.assertEqual(e["client_ip"], "203.0.113.5")
        self.assertIsNone(e["client_host"] and None)
        self.assertEqual(e["sasl_username"], "jan@klant.nl")

    def test_inbound_client_without_login(self):
        e = maillog.parse_line("postfix/smtpd", f"{QID}: client=mail.example.org[198.51.100.7]")
        self.assertEqual((e["service"], e["client_host"], e["sasl_username"]), ("smtp", "mail.example.org", None))

    def test_pickup_resolves_user(self):
        e = maillog.parse_line("postfix/pickup", f"{QID}: uid=0 from=<root>")
        self.assertEqual((e["kind"], e["uid"], e["user"], e["from"]), ("pickup", 0, "root", "root"))

    def test_queue_and_message_id(self):
        q = maillog.parse_line("postfix/qmgr", f"{QID}: from=<Jan@Klant.nl>, size=4521, nrcpt=2 (queue active)")
        self.assertEqual((q["kind"], q["from"], q["size"], q["nrcpt"]), ("queued", "jan@klant.nl", 4521, 2))
        m = maillog.parse_line("postfix/cleanup", f"{QID}: message-id=<abc@klant.nl>")
        self.assertEqual(m["message_id"], "abc@klant.nl")
        self.assertEqual(maillog.parse_line("postfix/qmgr", f"{QID}: removed")["kind"], "removed")
        x = maillog.parse_line("postfix/qmgr", f"{QID}: from=<jan@klant.nl>, status=expired, returned to sender")
        self.assertEqual(x["kind"], "expired")

    def test_delivery(self):
        e = maillog.parse_line(
            "postfix/smtp",
            f"{QID}: to=<piet@example.org>, orig_to=<p@example.org>, relay=mx.example.org[198.51.100.9]:25, delay=1.2, "
            "delays=0.1/0/0.5/0.6, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as 123, thanks)",
        )
        self.assertEqual(e["status"], "sent")
        self.assertEqual(e["to"], "piet@example.org")
        self.assertEqual(e["orig_to"], "p@example.org")
        self.assertEqual(e["relay"], "mx.example.org[198.51.100.9]:25")
        self.assertEqual(e["delay"], 1.2)
        self.assertFalse(e["local"])
        self.assertEqual(e["response"], "250 2.0.0 Ok: queued as 123, thanks")

    def test_local_delivery_and_bounce(self):
        e = maillog.parse_line("postfix/lmtp", f"{QID}: to=<jan@klant.nl>, relay=klant[private/dovecot-lmtp], delay=0.1, dsn=2.0.0, status=sent (250 2.0.0 Saved)")
        self.assertTrue(e["local"])
        d = maillog.parse_line("postfix/smtp", f"{QID}: to=<x@nope.example>, relay=none, delay=0.2, dsn=5.4.4, status=bounced (Host or domain name not found)")
        self.assertEqual((d["status"], d["dsn"]), ("bounced", "5.4.4"))
        b = maillog.parse_line("postfix/bounce", f"{QID}: sender non-delivery notification: 4Xk9M01abcdEfg")
        self.assertEqual((b["kind"], b["child_qid"]), ("bounce", "4Xk9M01abcdEfg"))

    def test_noqueue_reject(self):
        e = maillog.parse_line(
            "postfix/smtpd",
            "NOQUEUE: reject: RCPT from unknown[203.0.113.66]: 554 5.7.1 <x@other.example>: Relay access denied; "
            "from=<spam@bad.example> to=<x@other.example> proto=ESMTP helo=<bad>",
        )
        self.assertIsNone(e["qid"])
        self.assertEqual((e["kind"], e["code"], e["dsn"], e["client_ip"]), ("reject", "554", "5.7.1", "203.0.113.66"))
        self.assertEqual((e["from"], e["to"]), ("spam@bad.example", "x@other.example"))
        self.assertIn("Relay access denied", e["reason"])

    def test_milter_reject(self):
        e = maillog.parse_line(
            "postfix/cleanup",
            f"{QID}: milter-reject: END-OF-MESSAGE from unknown[203.0.113.66]: 5.7.1 Spam message rejected; "
            "from=<spam@bad.example> to=<jan@klant.nl> proto=ESMTP helo=<bad>",
        )
        self.assertEqual((e["qid"], e["action"], e["dsn"], e["reason"]), (QID, "milter-reject", "5.7.1", "Spam message rejected"))

    def test_ignores_other_lines(self):
        self.assertIsNone(maillog.parse_line("dovecot", "imap-login: Login: user=<jan@klant.nl>"))
        self.assertIsNone(maillog.parse_line("postfix/smtpd", "connect from unknown[203.0.113.5]"))
        self.assertIsNone(maillog.parse_line("postfix/smtp", f"{QID}: lost connection with mx[1.2.3.4]"))


class JournalTest(unittest.TestCase):
    def test_parse_journal_and_limit(self):
        lines = [
            entry("postfix/smtpd", f"{QID}: client=unknown[203.0.113.5]", 1),
            entry("dovecot", "something", 2),
            "not json",
            entry("postfix/qmgr", f"{QID}: removed", 3),
        ]
        r = maillog.parse_journal(io.StringIO("\n".join(lines) + "\n"), limit=10)
        self.assertEqual([e["kind"] for e in r["events"]], ["client", "removed"])
        self.assertEqual(r["cursor"], "s=abc;i=3")
        self.assertFalse(r["more"])
        self.assertAlmostEqual(r["events"][0]["time"], 1_762_420_001.0)

        r = maillog.parse_journal(iter(lines), limit=1)
        self.assertEqual((r["cursor"], r["more"]), ("s=abc;i=1", True))

    def test_byte_list_message(self):
        line = json.dumps({"__CURSOR": "c", "SYSLOG_IDENTIFIER": "postfix/cleanup",
                           "MESSAGE": list(f"{QID}: message-id=<\xe9@x>".encode("latin-1"))})
        r = maillog.parse_journal([line], 5)
        self.assertEqual(r["events"][0]["kind"], "message_id")

    def test_rejects_bad_params(self):
        for params in ({"cursor": "a b"}, {"cursor": "x;\nrm"}, {"limit": 0}, {"limit": True}, {"since_hours": 10_000}):
            with self.assertRaises(ActionError):
                maillog.log_read(params)


class HistoryTest(unittest.TestCase):
    def test_parse_history(self):
        rows = maillog.parse_history({"version": 2, "rows": [
            {"qid": QID, "message-id": "<abc@klant.nl>", "score": 12.345, "required_score": 15, "action": "add header",
             "user": "jan@klant.nl", "ip": "203.0.113.5", "subject": "Hello", "unix_time": 1762420000,
             "symbols": {"BAYES_SPAM": {"score": 5.1}, "R_DKIM_ALLOW": {"score": -0.2}, "ZERO": {"score": 0}, "RBL": {"score": 7}}},
            {"qid": "undef", "message-id": "undef"},
            "junk",
        ]})
        self.assertEqual(len(rows), 1)
        r = rows[0]
        self.assertEqual((r["qid"], r["message_id"], r["score"], r["user"]), (QID, "abc@klant.nl", 12.35, "jan@klant.nl"))
        self.assertEqual([s["name"] for s in r["symbols"]], ["RBL", "BAYES_SPAM", "R_DKIM_ALLOW"])


if __name__ == "__main__":
    unittest.main()
