# rhis/web-rules: Redirects & access

Per website, for customers (client panel) and admins (admin panel), opened from
the tiles "Redirects & error pages" and "Access & passwords" on the domain page:

- **Redirects** 301/302/307/308 from a path (optionally everything below it,
  keeping the rest of the path) to a path or another website, query string kept
  or dropped.
- **Password-protected folders** (HTTP basic auth, SHA-512 crypt hashes, also
  covers PHP pages below the folder; `/` protects the whole site).
- **Custom error pages** for 400, 401, 403, 404, 410, 429, 500, 502, 503, 504.
- **Force HTTPS** (default on) and **HSTS** (duration, includeSubDomains, preload).
- **IP blocking** (block listed / only allow listed) and **country blocking**
  (GeoIP, DB-IP Lite country database, CC BY 4.0).

The panel keeps all rules of a website in `site_web_rules` and sends all of them
to the agent with every change (`webrules.apply`). The agent writes
`/etc/nginx/whp/sites/<domain>/30-web-rules.conf` (server-level directives),
`/etc/nginx/whp/auth/<domain>/*.htpasswd` and `/etc/nginx/whp/hsts/<domain>.conf`,
tests nginx and reloads. When nginx refuses, the old files are put back and the
panel saves nothing. See `agent/whp_agent/actions/web_rules.py`.

Server side: installer module `56-web-rules.sh` (directories, `libnginx-mod-http-geoip2`,
`whp-geoip-update` + monthly timer) and `whp-site https <domain> force|allow`.

Config: `WHP_WEB_RULES_CUSTOMER=false` hides the feature from customers.
Team members need the "Website settings" area.
