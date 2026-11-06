# Monthly hosting report

Package `packages/reports` (`Rhis\Reports`). Every month each customer gets one RHIS branded
mail (EN/NL, in the customer's language) about the month before: visitors, uptime, backups,
security, mail, updates and usage. It shows customers the value of their hosting.

## Where the numbers come from

| Part | Source |
| --- | --- |
| Visitors, page views, busiest day | `visit_stats` (day rows, see Statistics) |
| Uptime | provider `Reports::provide('uptime', ...)`, registered by the monitoring package |
| Backups | server snapshots per `site:<domain>` (agent `backup.targets` + `backup.list`, fetched once per run) and `client_backups` |
| Security | `security_findings` (found, fixed, open) and the HTTPS state of the websites |
| Email | `mail_log_messages` (received, spam stopped, sent) |
| Updates | `wordpress_installs`, activity `wordpress.update`, `server_task_runs` (task `upgrade`) |
| Usage | `websites.disk_used_mb` / quota, `website_traffic` |

Every part is optional: a missing package or table hides that part of the mail.

## Data from other packages

```php
if (class_exists(\Rhis\Reports\Reports::class)) {
    \Rhis\Reports\Reports::provide('uptime', fn (Customer $c, CarbonImmutable $from, CarbonImmutable $to) => [
        'sites' => ['example.nl' => ['uptime' => 99.98, 'downtime_minutes' => 9, 'incidents' => 1, 'response_ms' => 230]],
    ]); // or null when there is no data
}
```

## Sending

- `whp:reports:send` runs hourly. From the configured day and time (default the 1st at 09:00)
  it sends the report about last month to every customer with a website who did not opt out.
  A month is never sent twice; a failing mail is retried at most three times.
- `whp:reports:send --month=2026-10 --customer=12 --force` sends by hand.
- Admin: Hosting services > Monthly reports (settings, preview EN/NL, test copy to yourself,
  send, switch a customer off). Client: Account > Monthly report (web version per month,
  opt-out and extra recipients). The mail's footer and `List-Unsubscribe` link go there.
