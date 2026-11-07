# rhis/site-logs

Log viewer per website, for customers (`/logs`) and admins (`/admin/logs`, menu
"Website logs"), plus a "Logs and errors" tile on the domain page.

| Log | File on the server |
| --- | --- |
| Access log | `/var/www/vhosts/<domain>/logs/access.log` (Nginx, format `whp`) |
| Error log | `/var/www/vhosts/<domain>/logs/error.log` (Nginx) |
| PHP errors | `/var/www/vhosts/<domain>/logs/php-error.log` (`error_log` of the site pool) |
| PHP-FPM | `/var/log/webhosting-panel/php-fpm/<site id>.log` (the site's FPM master) |

- Search (words, `-word` leaves lines out), status class (2xx–5xx) and method
  filters for the access log, level filters for the others, filter on an IP.
- Older days: logrotate keeps 14 daily copies (`access.log.1`, `.2.gz`, …),
  selectable in the viewer; compressed copies are streamed by the agent.
- Live view: the browser polls `read` with the cursor of the last answer every
  `WHP_SITE_LOGS_TAIL_INTERVAL` seconds (default 3) and only gets new lines. It
  pauses while you scroll away and when the tab is hidden, and follows a rotation.
- Summary of the loaded lines: requests, 4xx/5xx, average response time, top IPs
  and paths (click to filter).

Agent actions: `sitelog.files`, `sitelog.read` (`agent/whp_agent/actions/sitelogs.py`).
The site user owns its `logs/` folder, so the agent opens every path step by
step with `O_NOFOLLOW` and only reads regular files with one link.

Server side: `installer/modules/56-site-logs.sh` adds a drop-in to
`logrotate.service` that runs `whp-site-logrotate` first (one block per website,
rotated as the website's own user, `nocreate`) and tells Nginx to reopen its logs
afterwards. Days to keep: `WHP_SITE_LOG_DAYS` (default 14).

Settings (`config/site-logs.php`): `WHP_SITE_LOGS_CUSTOMER=false` hides the logs
from customers. Team members need the "Website settings" area.
