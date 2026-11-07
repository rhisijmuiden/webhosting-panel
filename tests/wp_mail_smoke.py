"""End to end check of WordPress website mail (run by smoke.sh as root, in the mail part).

wp.test runs WordPress, info@example.test is a local mailbox and test.wp.test is
a staging copy of wp.test (staging_smoke.py). WordPress' own wp_mail() has to go
through the loopback Postfix service, come out signed with the DKIM key of
wp.test and land in the mailbox; a contact form "from" a visitor is sent from
the website with the visitor as Reply-To; the staging copy never sends.
Everything runs through WP-CLI (NoNewPrivileges), like the system WP-cron.
Usage: python3 tests/wp_mail_smoke.py wp.test test.wp.test info@example.test
"""

import glob
import json
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import mail, wp_mail  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

live, staging, mailbox = sys.argv[1], sys.argv[2], sys.argv[3]
local, _, mdomain = mailbox.partition("@")
maildir = f"/var/vmail/{mdomain}/{local}/Maildir"


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {detail}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def wait_for(marker, timeout=60):
    """The delivered message that contains marker (headers + body as text)."""
    for _ in range(timeout):
        for f in glob.glob(f"{maildir}/new/*") + glob.glob(f"{maildir}/cur/*"):
            with open(f, errors="replace") as fh:
                text = fh.read()
            if marker in text:
                return text
        time.sleep(1)
    return None


def headers(text):
    return text.split("\n\n", 1)[0].replace("\n\t", " ").replace("\n ", " ")


try:
    mail.dkim_generate({"domain": live})
except ActionError as e:
    if e.code != "conflict":
        raise
step("website SMTP service answers on loopback", wp_mail.smtp_available())

out = wp_mail.mail_apply({"domain": live, "enabled": True, "from_name": "WP Smoke"})
step("website mail switched on", out["enabled"] and out["plugin_current"] and out["dkim"].get(live) is True, out)
docroot = wordpress.site_info(live)["docroot"]
step("mu-plugin and settings belong to the site user",
     os.stat(os.path.join(docroot, wp_mail.PLUGIN)).st_uid == os.stat(os.path.join(docroot, "wp-config.php")).st_uid)

res = wp_mail.mail_test({"domain": live, "to": mailbox, "subject": "WPM smoke one"})
step("test message accepted through SMTP", res["ok"] and res["routed"] and res["mailer"] == "smtp"
     and res["host"] == f"127.0.0.1:{wp_mail.SMTP_PORT}" and bool(res.get("queue_id")), res)
msg = wait_for("WPM smoke one")
step("test message delivered to the mailbox", msg is not None)
h = headers(msg)
step("sent from the website, envelope in the same domain", f"From: WP Smoke <noreply@{live}>" in h
     and f"Return-Path: <noreply@{live}>" in h, h[:1500])
step("signed with the DKIM key of the website", "DKIM-Signature:" in h and f"d={live}" in h and "s=whp" in h, h[:2000])
step("tells which website sent it", f"X-Website: {live}" in h)

# A contact form that sends "from" the visitor.
code = ("<?php $ok = wp_mail($args[0], 'WPM smoke form', 'question', ['From: Visitor <visitor@gmail.com>']);"
        " echo $ok ? 'SENT' : 'FAILED';")
proc = wordpress.wp(live, ["eval-file", "-", mailbox], input_text=code, timeout=120, plugins=True)
step("contact form message sent", "SENT" in proc.stdout, proc.stdout + proc.stderr)
msg = wait_for("WPM smoke form")
step("contact form message delivered", msg is not None)
h = headers(msg)
step("visitor becomes Reply-To, From stays the website", f"noreply@{live}" in h.split("From:", 1)[1].split("\n", 1)[0]
     and "Reply-To: Visitor <visitor@gmail.com>" in h, h[:1500])

status = wp_mail.mail_status({"domain": live})
step("recent mail log shows both messages", [e["subject"] for e in status["log"][:2]] == ["WPM smoke form", "WPM smoke one"]
     and all(e["ok"] for e in status["log"][:2]), json.dumps(status["log"][:3]))
step("log entry has the Postfix queue id", status["log"][1]["queue_id"] == res["queue_id"], json.dumps(status["log"][:2]))

# The staging copy: never switched on, and its mail stays held back.
try:
    wp_mail.mail_apply({"domain": staging, "enabled": True})
    step("staging copy refuses website mail", False)
except ActionError as e:
    step("staging copy refuses website mail", e.code == "conflict", str(e))
res = wp_mail.mail_test({"domain": staging, "to": mailbox, "subject": "WPM smoke staging"})
step("staging copy holds its test message back", not res["ok"] and res["staging"], res)
time.sleep(5)
step("nothing from the staging copy arrived", wait_for("WPM smoke staging", timeout=1) is None)

out = wp_mail.mail_apply({"domain": live, "enabled": False})
step("website mail switched off again, files removed", not out["enabled"]
     and not os.path.exists(os.path.join(docroot, wp_mail.PLUGIN)) and not os.path.exists(os.path.join(docroot, wp_mail.CONFIG_DIR)))
