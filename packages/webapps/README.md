# Node.js and Python apps

Websites can run Node.js and Python apps next to PHP: Express, Next.js, Nuxt,
Fastify, Django, Flask, FastAPI and so on. Native on Debian 13, no Docker.

Screens: admin **Additional services → Node.js & Python** (`/admin/webapps`,
runtime versions at `/admin/webapps/runtimes`), customers **Websites → Node.js &
Python** (`/webapps`). Code: `packages/webapps` (`Rhis\WebApps\`), agent
`agent/whp_agent/actions/webapps.py`, installer `installer/modules/57-runtimes.sh`.

## How an app runs

| What | How |
| --- | --- |
| Process | `whp-webapp-<id>.service` (systemd), `User=` the website's own Linux user, `Slice=` the subscription's slice (`whp-sites-s<id>.slice`), so the package's CPU, memory, IO and process caps apply to the app too |
| Per-app caps | Package limits `webapp_memory_mb` (MemoryHigh 90 %, MemoryMax 100 %) and `webapp_cpu_percent` (CPUQuota); `max_webapps` apps per website (NULL unlimited, 0 = feature off) |
| Hardening | `ProtectSystem=strict` (writable: the website folder only), `ProtectHome`, `PrivateTmp`, `PrivateDevices`, `NoNewPrivileges`, kernel/cgroup protections, `RestrictAddressFamilies`, `SocketBindAllow=tcp:<port>` + `SocketBindDeny=any` (an app can only listen on its own port) |
| Port | 30000-39999, given out by the agent and kept for the app's lifetime; the app gets `PORT`, `HOST=127.0.0.1` and `HOSTNAME=127.0.0.1` |
| Environment | Panel stores variables encrypted (`encrypted:array`); the agent writes `/etc/webhosting-panel/webapps/<id>.env` (root, 0600) as `EnvironmentFile`. `NODE_ENV=production` / `PYTHONUNBUFFERED=1` by default |
| Start | `ExecStart=/bin/sh -c "exec <start command>"` in the app folder; `PATH` starts with `node_modules/.bin` or `.venv/bin`, then the chosen runtime |
| Restart | `Restart=on-failure`, 3 s, at most 10 starts in 5 minutes; enabled units start at boot. Stop in the panel = disabled (stays off after a reboot) |
| Logs | journald (`journalctl -u whp-webapp-<id>`), shown live in the panel |

## Nginx

- Address `/`: the whole site is the app. `whp-site proxy <domain> <port>`
  stores `SITE_PROXY` and renders `installer/templates/nginx/site-proxy-body.conf`
  (no PHP; other app snippets and `.well-known` keep working). `whp-site proxy
  <domain> off` goes back to PHP.
- Address `/api` (any path): `/etc/nginx/whp/sites/<domain>/webapp-<id>.conf`
  with `location ^~ /api/`, optional prefix stripping. PHP keeps the rest.
- Both use `/etc/nginx/whp/proxy.conf` (forwarded headers, WebSockets through
  the `$whp_connection_upgrade` map in `whp-http.conf`). A stopped or crashing
  app shows `pages/app-down.html` (EN/NL, refreshes itself) instead of a 502.

## Runtimes

`/opt/whp-runtimes/node/<major>/` (official nodejs.org builds, checked against
`SHASUMS256.txt`) and `/opt/whp-runtimes/python/bin/python3.<minor>`
(python-build-standalone through uv, `/usr/local/bin/uv` pinned in the module).
The installer adds Node.js 24 and Python 3.13 once; the admin installs, updates
(newest patch, running apps restart) or removes versions in the panel.
Offered: Node.js 22/24/26, Python 3.11-3.14; a version past its end of life is
no longer offered for new apps (running apps keep working, the page marks it).

## Dependencies, build and Git

**Install dependencies** runs as the website user via `systemd-run` (MemoryMax 2G):
Node.js `npm ci` (or `npm install` without a lock file), Python `uv venv .venv`
+ `uv pip install -r requirements.txt` (or `pyproject.toml`), then the optional
build command; the app restarts after.

Git deployments (`packages/git`) to the app folder (or a folder above it): with
"install dependencies on every Git deployment" on, `git.py` calls
`webapps.prepare_deploy()` in the new files **before** a zero-downtime release
goes live (a failing install or build stops the deployment, visitors keep the
old release) and `webapps.restart_after_deploy()` after the switch.

## Agent actions

`runtime.list`, `runtime.install` (job), `runtime.remove`, `webapp.apply`,
`webapp.status`, `webapp.list`, `webapp.control` (start/stop/restart),
`webapp.logs`, `webapp.install` (job), `webapp.suspend`, `webapp.remove`.
The panel sends the full settings with every `webapp.apply`; the agent keeps
`/var/lib/webhosting-panel/webapps/<id>.json` (no secrets).

Website suspended → apps stopped; active again → started. Website removed →
apps removed. Package changed → apps applied again with the new caps.
Team members with the "Git deployments" area can manage apps too.
