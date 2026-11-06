# Server management

Admin: Tools & settings > Server management (`/admin/server`), package
`packages/server-admin` (`Rhis\ServerAdmin\`), agent module
`agent/whp_agent/actions/server.py`.

## What it does

- **Updates & reboot**: pending Debian packages (`apt-get -s dist-upgrade`), security
  ones marked, with the CVEs they fix and their CVSS score from the last security check.
  Install security updates (`unattended-upgrade`, same origins as the installer) or all
  (`apt-get upgrade --with-new-pkgs`, never removes). Reboot needs the hostname typed;
  every website is checked before and after. **There is no shutdown**, on purpose.
- **Maintenance window** (default every night 03:00-04:00, server time zone): installs
  updates, reboots only when needed (newer kernel, `/run/reboot-required`, or services
  still mapping deleted libraries) and only inside the window, waits 2 minutes after boot,
  checks every service and website, restarts services that did not come back, checks
  again, mails. While the window is on, Debian's own daily unattended-upgrades run is
  switched off (`/etc/apt/apt.conf.d/21whp-panel-window`).
- **Critical CVEs**: the hourly security check downloads the waiting security updates,
  reads the CVE ids from each package's `changelog.Debian.gz` and looks up the CVSS base
  score at the NVD (cached in `/var/lib/webhosting-panel/server/cvss.json`). At or above
  the threshold (default 9.0) it mails the admins, installs at once and reboots after the
  set delay (default 15 minutes, or in the next window), with the same health checks.
  Each CVE is handled once.
- **PHP end of life** (dates in `config/server-admin.php`): customers get a mail
  `php_notice_days` before; after EOL + grace days every site moves to the lowest
  installed newer supported version, checked before and after (HTTP < 500), rolled back
  when it broke. Without a newer version installed, it is installed first
  (`server.php_install`: adds it to `WHP_PHP` in installer.env and runs
  `install.sh --upgrade`). When no site uses the old version any more it is purged
  (`server.php_remove`, never the panel's own PHP).
- **Scheduled tasks** (`server_tasks`, editable): security check (hourly), server cleanup
  (old kernels + apt cache, journal, site `tmp/` and `sessions/`, agent job files, core
  dumps; root-run but never follows symlinks), panel cleanup (activity log, run history,
  failed jobs, log files), database optimize (OPTIMIZE tables with lots of free space,
  ANALYZE all), website health check (mails when something new is down), PHP end of life.

Everything runs from `whp:server:tick` (every minute): it follows agent jobs, starts due
tasks and moves the maintenance session on, across the reboot. Every run is a
`server_task_runs` row with its log.

## Agent actions

`server.status`, `server.updates`, `server.security_scan` (job), `server.upgrade` (job,
`mode: security|all`), `server.apt_status`, `server.auto_updates`, `server.reboot`
(`delay` 5-86400 s, timer unit `whp-reboot`), `server.reboot_cancel`, `server.health`,
`server.db_optimize` (job), `server.cleanup` (job, `dry_run`), `server.php_versions`,
`server.php_install` (job), `server.php_remove` (job).

Package installs run in a transient unit `whp-apt-<id>` with their log in
`/var/log/webhosting-panel/apt/<id>.log`, so they survive an agent restart caused by the
upgrade itself; the panel reads them back with `server.apt_status`.
