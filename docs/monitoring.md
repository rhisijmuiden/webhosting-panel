# Monitoring and alerts

Package `packages/monitoring` (`Rhis\Monitoring\`), agent action `monitor.check`
(`agent/whp_agent/actions/monitor.py`).

## What is checked

Every few minutes (`interval`, default 5) the scheduler runs `whp:monitor:run`
(scheduled every minute, it only works when the interval has passed). One call to
the agent returns:

| Check | Key | Problem when |
| --- | --- | --- |
| Website answers (HTTP via 127.0.0.1, SNI) | `site:<id>` | no answer or HTTP 500+ for `fail_after` checks in a row (critical) |
| Certificate served for the domain | `ssl:<id>` | fewer than `ssl_warning_days` (warning) / `ssl_critical_days` days left, expired or for another host (critical). Not judged while the website itself is down |
| Disks (ext4, xfs, btrfs, ...) | `disk:<mount>`, `inode:<mount>` | `disk_warning` / `disk_critical` percent used, `inode_warning` |
| Services (same list as Services) | `service:<unit>` | not active for `fail_after` checks |
| Memory, load, mail queue | `memory`, `load`, `mail_queue` | above the thresholds (warning) |
| The agent itself | `agent` | the panel cannot reach the agent twice in a row |

States live in `monitor_checks`. A state that turns into a problem opens a row in
`monitor_incidents`; it is closed when the check is fine again. Website checks are
also stored per check in `monitor_samples` (kept `samples_days`, default 7) and
added up per day in `monitor_daily` (kept forever; also `component:<key>` rows for
the status page).

## Alerts

`monitor_channels`: mail (addresses, or empty = every active administrator in
their own language) or Microsoft Teams. Each channel picks "warnings and
critical" or "only critical", whether it also wants "solved" messages and the
language. One message per channel per round, with everything new.

Teams: use a **Workflows** webhook (channel > ... > Workflows > "Post to a channel
when a webhook request is received"). Microsoft retired the Office 365 connectors;
the panel posts an Adaptive Card, which both kinds accept. The URL is stored
encrypted and must be https and not a private address.

- New problems are reported once confirmed (`fail_after`), reminders every
  `reminder_hours` while open, and "solved" only for problems that were reported.
- While server maintenance (`packages/server-admin`: task `maintenance` or
  `reboot`) runs, problems are recorded but alerts wait until it is done.
- With monitoring on, the hourly health check of Server management no longer
  mails (it logs "Alerts are sent by Monitoring").
- Customers can switch on a mail for their own websites (down / works again)
  under Status in their panel (`monitor_contacts`), when the administrator allows
  it (`customer_alerts`).

**Heartbeat**: a server cannot report that it is down itself. Set `heartbeat_url`
to a push monitor of an external service (Healthchecks.io, Uptime Kuma, ...);
the panel calls it after every round and that service alerts when calls stop.

## Status pages

- Public `/status` (switch `status_page`): Websites, Email, Databases and Control
  panel (`config/monitoring.php` maps services to these), 90 days of uptime bars,
  admin messages (`monitor_notices`: maintenance, information, degraded, outage)
  and the maintenance window of Server management. Never customer names or domains.
- Client `/uptime`: their own websites (status, response time, 90 days,
  certificate), interruptions, the platform and the alert choice. Team members
  with the "Website settings" area can open it.
- Admin `/admin/monitoring`: overview, per website detail (24 h response graph),
  incidents, alert channels with a test button, status page messages, settings.

## Monthly report

When `packages/reports` is present, the provider registers
`Reports::provide('uptime', ...)` with `Uptime::report($customer, $from, $to)`:
uptime %, downtime minutes (from incidents), number of incidents and average
response time per website.
