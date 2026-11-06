# Mail log (admin)

Admin → **Mail log** (`/admin/mail-log`) shows every message the server sent,
received or refused, tied to a customer, domain, mailbox and website. It is
meant to spot spammers quickly: a hacked contact form or a stolen mailbox
password shows up as a customer with a high volume, many bounces or spam.

## How it works

| Step | Where |
| --- | --- |
| Postfix logs to the systemd journal (facility mail). The installer sets `enable_long_queue_ids = yes` so queue ids are unique. | `installer/modules/60-mail.sh` |
| Agent `mail.log_read {cursor?, limit?, since_hours?}` reads `journalctl -o json SYSLOG_FACILITY=2` after the cursor and returns one parsed event per Postfix line (client, pickup, message_id, queued, delivery, reject, bounce, expired, removed) plus the new cursor. | `agent/whp_agent/actions/maillog.py` |
| Agent `mail.spam_history` reads Rspamd's `/history` (history_redis, 5000 rows in Valkey). | same, `installer/templates/rspamd/history_redis.conf` |
| `dns-mail:maillog` (every minute) merges the events per queue id into `mail_log_messages` / `mail_log_recipients`, adds the spam score and links the customer. `dns-mail:maillog --prune` (nightly) deletes old rows. | `packages/dns-mail/src/Services/MailLogCollector.php` |

Who sent it:

- **SMTP login** (`sasl_username`) → the mailbox → its domain → customer
- **Website (PHP)** (`pickup uid=…`) → the site's system user → website → customer
- **From outside** → the customer of the recipient's domain
- otherwise the sender's domain (also subdomains such as `news.klant.nl`)

## Spammer signals

The page flags a customer when, for outgoing mail:

- messages in the last hour ≥ *Flag a customer from* (default 200), or
- bounced + refused ≥ *bounce rate* % (default 20 %, with at least 20 messages), or
- at least 10 % (minimum 5) has a spam score of 6 or more.

Thresholds and how long entries are kept (default 30 days) are set at the
bottom of the mail log page. Filters: customer, domain, mailbox / login /
website user, direction, sent through, status, period or dates, spam only,
free text (address, subject, queue id, message id, IP). The filtered view can
be exported as CSV.
