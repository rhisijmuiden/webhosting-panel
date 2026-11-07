# rhis/waf – Web application firewall

ModSecurity v3 in Nginx with the OWASP Core Rule Set (CRS), both from Debian
(`libnginx-mod-http-modsecurity`, `modsecurity-crs`).

## Why ModSecurity + CRS (and not CrowdSec AppSec)

* Per website on, off or detect only, and per-site rule exceptions: native
  ModSecurity features (`modsecurity on;` per server block, `SecRuleEngine`,
  `SecRuleRemoveById`, `ctl:` actions). CrowdSec AppSec has one global
  configuration and would need the Lua bouncer (OpenResty modules) in Nginx.
* Runs inside Nginx: no extra request to a local API per visit.
* The CRS is the standard rule set (anomaly scoring, paranoia levels), with a
  documented false-positive process.
* CrowdSec keeps doing what it does best: log-based IP bans and the community
  blocklist (packages/security).

## How it fits together

| Where | What |
|---|---|
| `installer/modules/58-waf.sh` | packages, `/etc/nginx/whp/waf/*.conf`, `/etc/nginx/conf.d/whp-waf.conf` (rules loaded once, `modsecurity off`), audit log + logrotate |
| `agent/whp_agent/actions/waf.py` | `waf.apply` (server settings, per-site files, `nginx -t`, rollback), `waf.events` (JSON audit log from a cursor), `waf.status` |
| this package | settings, modes and exceptions, admin pages (`/admin/web-firewall`), customer pages (`/web-firewall`), Security center card, events table |

Per site the agent writes `/etc/nginx/whp/sites/<domain>/20-waf.conf`
(`modsecurity on`, transaction id `<domain>~$request_id`, so every event names
its site) and `/etc/nginx/whp/waf/sites/<domain>.conf` (`SecRuleEngine On` or
`DetectionOnly` plus the exceptions). Domain aliases share the site's server
body and therefore its firewall.

`whp:waf:collect` runs every five minutes: it applies the settings again when
they changed or a new website was not on the server yet, fetches new events and
drops events older than `waf.keep_days` (30).

Default mode on a new server is **detect only**; the admin switches the default
to blocking in Settings once the events show no false alarms.

The audit log holds request headers (not bodies). The agent only passes method,
URI, Host and User-Agent to the panel, never cookies or authorization headers.
