# Cron jobs

Customers (and admins for them) schedule tasks per website: a shell command,
a PHP script or a URL. Menu: **Cron jobs** (client: Websites, admin: Additional
services), and a tile on the Dashboard tab of the domain page.

## How a job runs

Every job is a systemd timer plus a oneshot service, written by the agent
(`agent/whp_agent/actions/cron.py`):

| File | What |
|---|---|
| `/etc/systemd/system/whp-cron-<id>.timer` | `OnCalendar=` lines made from the cron expression, in the panel's time zone |
| `/etc/systemd/system/whp-cron-<id>.service` | `bash -c` as the site user, `Slice=` of the subscription, time limit |
| `/var/lib/webhosting-panel/cron/<id>.json` | state (domain, type, schedule) |
| `/var/lib/webhosting-panel/cron/runs/<id>.jsonl` | the last 50 runs (result, duration, last 64 KB of output) |
| `/usr/local/lib/whp-cron/php/<version>/php` | symlink, so `php` in a command is the website's PHP version |

- **Site user and limits.** The service runs as the website's own Linux user in
  the subscription's slice (`whp-sites-s<id>.slice`), so CPU, memory, process
  and disk quota limits of the package apply, like for PHP-FPM, WP-CLI and SSH.
  Hardening: `NoNewPrivileges`, `ProtectSystem=full`, `ProtectHome`, `PrivateTmp`.
- **Never twice at once.** A oneshot service that still runs is not started
  again by its timer; that run is skipped.
- **Time limit.** `TimeoutStartSec` (1 minute to 24 hours, default 1 hour). A
  run that takes longer is stopped and saved as "Took too long".
- **Output.** stdout and stderr go to the journal. After every run
  `ExecStopPost=+.../cron_finish.py` (root, standard library only) reads the
  result from systemd (`SERVICE_RESULT`, `EXIT_STATUS`), the output of that
  invocation from the journal and appends one JSON line.
- **Schedule.** Five field cron syntax: numbers, `*`, ranges, steps, lists,
  `jan`-`dec`, `sun`-`sat`, 7 = Sunday, and `@hourly`, `@daily`, `@weekly`,
  `@monthly`, `@yearly`. When both day of month and day of week are limited
  the job runs when either matches (like cron): two `OnCalendar=` lines.
- **Types.** Command: `bash -c` in the website folder. PHP script: path inside
  the website folder, run with `/usr/bin/php<site version>`. URL: `curl -f`
  (status 400 or higher fails), body discarded.
- **Website changes.** Suspending a website pauses its timers, activating it
  starts them again, removing it removes the jobs. A new PHP version or
  subscription slice (`site.php`, `site.owner`) rewrites the units.

## Panel

Package `packages/cron` (`Rhis\Cron\`): tables `cron_jobs` and `cron_runs`,
`CronManager` (agent calls, run sync, mails), `Schedule` (validation, next runs,
description in words). `whp:cron:sync` runs every minute: it fetches finished
runs (`cron.runs`), keeps the last 25 per job and mails the customer (or the
address set on the job) when a run fails: once when it starts failing, then at
most every 24 hours while it keeps failing. The job page also fetches the runs
of that job right away, so Run now shows its result within seconds.

Package limit: **Cron jobs** (`max_cronjobs`) per website; empty = unlimited
(capped at `WHP_CRON_MAX_PER_WEBSITE`, 50). Team members need the "Website
settings" area.

Settings in `panel.env`:

| Variable | Default | |
|---|---|---|
| `WHP_CRON_CUSTOMER_ACCESS` | `true` | customers manage their own jobs |
| `WHP_CRON_MAX_PER_WEBSITE` | `50` | hard cap per website |
| `WHP_CRON_MIN_INTERVAL` | `1` | shortest interval customers may schedule (minutes) |
| `WHP_CRON_KEEP_RUNS` | `25` | runs kept per job in the panel |
| `WHP_CRON_REMIND_HOURS` | `24` | repeat the failure mail while it keeps failing |

## Agent actions

`cron.apply`, `cron.calendar` (check a schedule), `cron.list` (running, next
run), `cron.run` (start now, in the background), `cron.stop`, `cron.runs`
(finished after `since`), `cron.suspend`, `cron.remove`.
