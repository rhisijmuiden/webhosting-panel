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

Actions added by phase 2: `ping`, `system.info`, `service.status`,
`service.restart`, `firewall.list`, `firewall.add`, `firewall.delete`,
`crowdsec.decisions`, `crowdsec.ban`, `crowdsec.unban`, `panel.version`,
`panel.check_update`, `panel.update`, `panel.set_channel`, `job.status`.

Other phases add their own module (`dns.py`, `mail.py`, `backup.py`), for
example `backup.run` calling `whp-backup … --json`.

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
