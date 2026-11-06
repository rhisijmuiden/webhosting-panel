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

### Usage statistics

Every site runs as `whp-site-<id>.service` in
`/sys/fs/cgroup/whp.slice/whp-sites.slice/` (`<id>` is the domain with `.` and
`-` as `_`). The panel stores one sample a minute (`whp:metrics:collect`) and
rolls them up hourly (`whp:metrics:rollup`): minutes kept 48 hours, hours 90
days, days forever.

Other phases add their own module (`dns.py`, `mail.py`, `backup.py`), for
example `backup.run` calling `whp-backup … --json`.

Actions added by phase 4 (`backup.py`, see `backup/README.md`):
`backup.status`, `backup.targets`, `backup.target_add` (secrets such as
`password`, `ssh_key`, `onedrive_token` and `repo_key` go to whp-backup as
temporary files), `backup.target_test`, `backup.target_remove`,
`backup.target_export_key`, `backup.list`, `backup.schedule`,
`backup.get_schedule`, and the jobs `backup.run`, `backup.restore`,
`backup.check`, `backup.prune`. The screens live in `packages/backups`
(`Rhis\Backups\`, admin `/admin/backups`, client `/backups`).

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
