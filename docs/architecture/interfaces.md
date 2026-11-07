# Interfaces between the components

This file is the contract between the installer (phase 1), the panel and agent
(phase 2), DNS/mail/webmail (phase 3) and backups/CRM API (phase 4). Change it
in the same pull request as the code that needs the change.

## Repository layout

| Path | Owner | What |
| --- | --- | --- |
| `installer/` | phase 1 | Debian 13 install script and config templates |
| `panel/` | phase 2 | Laravel 12 app (admin + client panel), namespace `App\` |
| `agent/` | phase 2 | `whp-agent`, root daemon in Python 3 (stdlib only, Debian 13 ships 3.13) |
| `packages/<name>/` | each phase | Composer packages the panel loads through a path repository |
| `packages/dns-mail/` | phase 3 | Namespace `Rhis\DnsMail\` |
| `packages/apps/` | app installer | Namespace `Rhis\Apps\` |
| `webmail/` | phase 3 | SnappyMail theme and config |
| `backup/` | phase 4 | `whp-backup` CLI (restic + rclone, JSON output) |
| `docs/` | all | Documentation, `docs/api/` holds the CRM OpenAPI spec |
| `VERSION` | phase 2 | Current version, semver, read by the Info screen |

## Paths and users on the server

| What | Value |
| --- | --- |
| Code | `/opt/webhosting-panel` (a git clone of this repository) |
| Config | `/etc/webhosting-panel/` (`panel.env` is the Laravel `.env`, `agent.json`, `backup/`) |
| State | `/var/lib/webhosting-panel/` |
| Logs | `/var/log/webhosting-panel/` |
| Panel system user | `whp` (group `whp`): PHP-FPM pool, queue worker, scheduler |
| Panel PHP-FPM pool | PHP 8.4, socket `/run/php/whp-panel.sock`, root `/opt/webhosting-panel/panel/public` |
| Panel URL | `https://<hostname>:8443` (own Nginx vhost, own Let's Encrypt cert) |
| Database | MariaDB database `whp_panel`, user `whp_panel`, password in `panel.env` |
| Cache, queue, sessions | Valkey on `127.0.0.1:6379`, database 1 for the panel |
| Agent socket | `/run/whp-agent/agent.sock`, mode `0660`, owner `root:whp` |
| Systemd units | `whp-agent.service`, `whp-queue.service`, `whp-scheduler.timer` (all shipped in `agent/systemd/` and `panel/deploy/systemd/`) |

The installer creates the user, database, pool, vhost and `panel.env`, clones
the repository, then runs `agent/install.sh` and `panel/deploy/install.sh`.

## Agent protocol

The panel never runs anything as root. It sends requests to the agent over the
Unix socket: one JSON object per line, one response line per request.

```json
{"id": "8f0c…", "action": "service.status", "params": {"service": "nginx"}}
{"id": "8f0c…", "ok": true, "data": {"service": "nginx", "active": true}}
{"id": "8f0c…", "ok": false, "error": {"code": "invalid_params", "message": "…"}}
```

Only actions that are registered in the agent exist. An action is a Python
function in `agent/whp_agent/actions/<module>.py` decorated with
`@action("<module>.<name>")`. It validates its own params and never passes
them to a shell. Long work returns `{"job": "<id>"}` and is followed with
`job.status`.

Writing an action:

```python
from whp_agent.registry import action, ActionError
from whp_agent import validate as v
from whp_agent.run import run          # argv list, never a shell
from whp_agent.jobs import start_job   # for long work

@action("mail.dkim_get")
def dkim_get(params):
    domain = v.domain(params, "domain")
    ...
    raise ActionError("not_found", f"No DKIM key for {domain}")
```

Error codes: `invalid_params`, `not_found`, `unknown_action`, `forbidden`,
`conflict`, `command_failed`, `timeout`, `internal` (plus `unreachable` on the
PHP side when the socket is down). Only `root` and `whp` may connect
(checked with `SO_PEERCRED`).

Actions added by phase 2:

| Action | What |
| --- | --- |
| `ping`, `actions.list`, `system.info`, `job.status` | Basics |
| `service.list`, `service.status`, `service.restart` | systemd services |
| `firewall.list`, `firewall.add`, `firewall.delete` | Panel rules, see below |
| `crowdsec.decisions`, `crowdsec.metrics`, `crowdsec.ban`, `crowdsec.unban` | Through `/usr/bin/cscli` |
| `security.apply`, `security.site_activity`, `security.site_release`, `security.check_ip` | Login protection, see below |
| `site.list`, `site.add`, `site.show`, `site.php`, `site.cache`, `site.ssl`, `site.quota`, `site.remove`, `site.suspend`, `site.unsuspend` | Through the installer's `whp-site … --json` |
| `site.owner`, `subscription.limits` (`customer.limits`), `site.php_settings`, `db.limits`, `traffic.report` | Package limits: resource jail per subscription, PHP settings, traffic (see `docs/limits.md`, `docs/subscriptions.md`) |
| `php.extensions.list`, `.search_pecl`, `.install`, `.remove`, `.toggle` | Extra PHP extensions per version (apt or PECL; install/remove are jobs) |
| `stats.sample` | Server and per-site usage, rates since the previous call |
| `panel.version`, `panel.check_update`, `panel.update`, `panel.set_channel` | Updates and release channel |

### Firewall

The installer owns the base ruleset (`table inet whp`, policy drop, fixed
ports). The agent keeps panel rules in `/etc/webhosting-panel/firewall.json`
and writes `/etc/nftables.d/whp-panel.nft` with elements for the installer's
sets (`tcp_open`, `udp_open`, `blocked_v4`, `blocked_v6`), checks the ruleset
with `nft -c` and runs `systemctl reload nftables`.

### Login protection

`packages/security` (`Rhis\Security\`) owns the settings: thresholds per
service (`ssh`, `mail`, `panel`, `wordpress`) in Settings, per-site choices in
`site_protections`. Every change sends the complete set with
`security.apply`; the agent (`security.py`) writes CrowdSec scenarios
`whp/<service>-bf` (temporary block) and `whp/<service>-bf-perm` (permanent
block), the profiles with the ban durations, the allowlists, and drops the
hub's own login brute-force scenarios. It runs `crowdsec -t` before reloading
and puts the old files back when CrowdSec refuses them. Failed panel logins
(password and two-factor) are appended to `/var/log/webhosting-panel/auth.log`
in syslog format. Also `security.site_activity`, `security.site_release` and
`security.check_ip` for the customer screens. Other packages read
`Rhis\Security\Models\SiteProtection::wordpressProtected($website)` and never
write CrowdSec configuration themselves. `whp:security:apply` pushes the
settings again (run by `panel/deploy/install.sh` and after every update).

The server-wide allowlist is the list of **trusted IP addresses**. They are
never blocked: CrowdSec whitelists them (parser + LAPI allowlist) and the agent
writes `/etc/nftables.d/whp-trusted.nft` (sets `trusted_v4`/`trusted_v6` plus
accept rules for the open ports ahead of the panel's block sets and the SSH
brake). Admins logging in from a trusted address skip the two-factor code
(`App\Auth\TrustedIpTwoFactor`, logged as `auth.two_factor_skipped`); the
session asks for the code again once it comes from another address. Core only
knows the `App\Auth\TrustedNetworks` interface, which `rhis/security` binds.

### Usage statistics

Every site runs as `whp-site-<id>.service` in
`/sys/fs/cgroup/whp.slice/whp-sites.slice/`, or one level deeper in its
customer's `whp-sites-c<customer id>.slice` (`<id>` is the domain with `.` and
`-` as `_`). The panel stores one sample a minute (`whp:metrics:collect`) and
rolls them up hourly (`whp:metrics:rollup`): minutes kept 48 hours, hours 90
days, days forever.

Other phases add their own module (`dns.py`, `mail.py`, `backup.py`), for
example `backup.run` calling `whp-backup … --json`.

Mail log (`maillog.py`, see `docs/mail-log.md`): `mail.log_read` (Postfix
events from the journal after a cursor) and `mail.spam_history` (Rspamd scan
history).

Actions added by phase 4 (`backup.py`, see `backup/README.md`):
`backup.status`, `backup.targets`, `backup.target_add` (secrets such as
`password`, `ssh_key`, `onedrive_token` and `repo_key` go to whp-backup as
temporary files), `backup.target_test`, `backup.target_remove`,
`backup.target_export_key`, `backup.list`, `backup.schedule`,
`backup.get_schedule`, and the jobs `backup.run`, `backup.restore`,
`backup.check`, `backup.prune`. The screens live in `packages/backups`
(`Rhis\Backups\`, admin `/admin/backups`, client `/backups`).

WordPress toolkit (`wordpress.py`, screens in `packages/wordpress`, `Rhis\WordPress\`,
admin `/admin/wordpress`, client `/wordpress`):

- Shared helper `agent/whp_agent/wordpress.py` for every feature that touches WordPress
  (app installer, migrations): `site_info(domain)`, `detect(domain)` (reads files, runs no
  PHP), `wp(domain, args, path="", input_text=None)` and `as_user(site, argv, cwd)`.
  WP-CLI (`/usr/local/bin/wp`, installed by `installer/modules/50-php.sh`) always runs as
  the site user through `systemd-run` in `whp-sites.slice`, with the site's PHP version and
  `UMask=0022`, and by default with `--skip-plugins --skip-themes`. Never run it as root.
- Actions: `wp.detect`, `wp.status`, `wp.users`, `wp.set_password` (password over stdin),
  `wp.login_link` (single use link, 2 minutes, via the mu-plugin `whp-login.php`),
  `wp.cron_get`, `wp.cron_set` (`DISABLE_WP_CRON` plus `whp-wpcron-<site>-<path>.timer`),
  `wp.cron_run`, `wp.snapshots`, and the jobs `wp.update`, `wp.update_many`, `wp.restore`.
- Before an update the database, plugins and themes are saved in
  `/var/lib/webhosting-panel/wordpress/snapshots/<site>-<path>/` (last 2 kept). When the
  front page answered below 500 before the update and 500+ after, the update is rolled back.
- `site.php` rewrites the WP-cron units of the site, they call the PHP binary directly.

Actions added by the app installer (`databases.py`, `apps.py`, see `packages/apps/README.md`):

| Action | What |
| --- | --- |
| `db.list`, `db.create`, `db.drop` | MariaDB database + user per site (root over the unix socket, SQL on stdin). Ownership is kept in `/var/lib/webhosting-panel/databases.json`; a site can only drop its own databases. `db.create` returns the password once. |
| `apps.catalog`, `apps.list` | Installable apps with latest release and `maintained` (`max_age_days`), installed apps with version on disk and `update_available` (state in `/var/lib/webhosting-panel/apps/<domain>.json`) |
| `apps.install` | Job: `{domain, app, path, title, admin_user, admin_email, admin_password, locale}`. Empty folder only, runs as the site user, rolls back on failure. Result: `id, version, url, admin_url, database, page_cache_disabled` |
| `apps.remove` | Job: `{domain, id}` removes files, database and the Nginx snippet `/etc/nginx/whp/sites/<domain>/app-<id>.conf` |

Actions added by the Plesk migration (`migrate.py`, see `docs/migration.md`):
`migrate.key`, `migrate.connect`, `migrate.forget_host`, `migrate.credentials`
(mailbox passwords, returned only over the socket) and the jobs
`migrate.inventory` and `migrate.transfer`. `whp-site ssl <domain>
--import-cert <pem> --import-key <pem>` serves a certificate from another
server (SSL state `imported`) until Let's Encrypt succeeds. Websites own their
databases through the core table `site_databases` (`App\Models\SiteDatabase`).
The screens live in `packages/migration` (`Rhis\Migration\`, admin `/admin/migration`).

Actions added by the file manager (`files.py`, `terminal.py`, see `docs/file-manager.md`;
screens in `packages/files`, `Rhis\Files\`, client `/files`, admin `/admin/files`):
`files.list`, `files.search`, `files.read`, `files.write`, `files.upload`,
`files.download`, `files.mkdir`, `files.rename`, `files.move`, `files.copy`,
`files.delete`, `files.chmod` and the jobs `files.compress`, `files.extract`. Every
operation runs `agent/whp_agent/fileworker.py` as the site user, jailed to
`/var/www/vhosts/<domain>`. File contents pass through spool files in
`/var/lib/webhosting-panel/files-spool` (owner `whp`, 0700): params carry a spool
name (`up-`, `wr-`, `rd-`, `dl-` + 32 hex), never the data. `terminal.status`,
`terminal.start` (ttyd + bubblewrap as the site user, socket in
`/run/whp-terminal/<token>/`), `terminal.stop`.

Domain aliases (`domain_aliases.py`, screens and CRM API in `packages/domain-aliases`,
`Rhis\DomainAliases\`, admin `/admin/domain-aliases`, client `/domain-aliases`):
`site.alias_list`, `site.alias_add` `{domain, alias, mode: serve|redirect, www, ssl}`,
`site.alias_mode`, `site.alias_ssl`, `site.alias_remove` `{keep_cert}` through
`whp-site alias …`. State per site in `/var/lib/webhosting-panel/sites/<site>.aliases`
(`<alias> <mode> <www> <ssl>`); every alias has its own server block in the site's
vhost and its own certificate (cert name = alias). `whp-site show` returns
`domain_aliases`. Mail: `mail_domains.alias_of_id` (dns-mail) makes `MailPusher` add
`user@alias -> user@main` for every mailbox and alias. See `packages/domain-aliases/README.md`.

Git deployments (`git.py`, screens in `packages/git`, `Rhis\Git\`, admin `/admin/git`, client `/git`,
public webhook `POST /git/webhook/<uuid>`): `git.key`, `git.check`, `git.fetch`, `git.status`,
`git.rollback`, `git.remove`, `git.tools` and the job `git.deploy`. See `docs/git.md`.

Server management (`server.py`, screens in `packages/server-admin`, `Rhis\ServerAdmin\`, admin `/admin/server`):
`server.status`, `server.updates`, `server.upgrade`, `server.apt_status`, `server.auto_updates`,
`server.reboot`, `server.reboot_cancel`, `server.health`, `server.security_scan`, `server.db_optimize`,
`server.cleanup`, `server.php_versions`, `server.php_install`, `server.php_remove`. No shutdown action.
See `docs/server-management.md`.

Node.js and Python apps (`webapps.py`, screens in `packages/webapps`, `Rhis\WebApps\`, admin `/admin/webapps`,
client `/webapps`): `runtime.list`, `runtime.install` (job), `runtime.remove`, `webapp.apply`, `webapp.status`,
`webapp.list`, `webapp.control`, `webapp.logs`, `webapp.install` (job), `webapp.suspend`, `webapp.remove`.
`whp-site proxy <domain> <port>|off` serves a whole site from an app. Git deployments call
`webapps.prepare_deploy()` before a release goes live. See `docs/webapps.md`.

Monitoring (`monitor.py`, screens in `packages/monitoring`, `Rhis\Monitoring\`, admin `/admin/monitoring`,
client `/uptime`, public `/status`): `monitor.check` (read only) returns per website the HTTP answer and the
certificate it serves (SNI on 127.0.0.1:443: days left, issuer, host match), real disks with inode use,
service states, memory, load and the Postfix queue. See `docs/monitoring.md`.

Disk usage explorer (`disk_usage.py` + `diskscan.py`, screens in `packages/disk-usage`, `Rhis\DiskUsage\`,
admin `/admin/disk-usage`, client `/disk-usage`): `disk.scan` (job, one website), `disk.scan_all` (job, nightly,
skips scans younger than `max_age_hours`), `disk.usage` (one folder of the last scan: sub folders, largest files)
and `disk.summary` (scanned totals per website and the size of every MariaDB database). Scans are cached in
`/var/lib/webhosting-panel/disk-usage/<domain>.json`. See `packages/disk-usage/README.md`.

Extra FTP/SFTP accounts (`ftp.py`, ProFTPD from `installer/modules/75-ftp.sh`, screens in the core panel:
`App\Services\FtpAccounts`, admin `/admin/ftp-accounts`, client `/ftp-accounts`): `ftp.sync` (the complete list of
virtual users, password hashes only) and `ftp.status`. FTPS on 21 (TLS required), SFTP on 2222. See `docs/ftp-accounts.md`.

Cron jobs (`cron.py`, screens in `packages/cron`, `Rhis\Cron\`, admin `/admin/cron`, client `/cron`):
`cron.apply`, `cron.calendar`, `cron.list`, `cron.run`, `cron.stop`, `cron.runs`, `cron.suspend`, `cron.remove`.
One `whp-cron-<id>.timer` + oneshot service per job (site user, subscription slice); `cron_finish.py` saves
every run (ExecStopPost). `site.php` / `site.owner` rewrite the units. See `docs/cron.md`.

## Release channels

| Channel | Git branch |
| --- | --- |
| production | `main` |
| test | `test` |

`panel.update` runs `agent/scripts/update.sh <channel>`: fetch, check out the
channel branch, `composer install --no-dev`, `php artisan migrate --force`,
cache config, restart the pool and the queue worker. Built frontend assets are
committed in `panel/public/build`, so the server needs no Node.js.

## Extending the panel from a package

A package registers itself with a Laravel service provider (package
discovery). It can:

- add menu items: `App\Support\Menu::admin()->add(...)` or `Menu::client()->add(...)`
- use the layout: `<x-layouts.admin>` / `<x-layouts.client>` and the UI
  components `<x-ui.card>`, `<x-ui.pill>`, `<x-ui.button>`, `<x-ui.table>`
- call the agent: `app(App\Services\Agent\AgentClient::class)->call('backup.run', [...])`
- ship translations in `lang/en` and `lang/nl` (English is the default)
- add routes under the `admin` or `client` middleware groups
