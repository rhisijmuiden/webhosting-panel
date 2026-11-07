# SSL certificates

Every website starts with a free Let's Encrypt certificate (HTTP-01, retried
hourly by `whp-cert-retry.timer` until DNS points to the server). Under
**SSL certificates** (admin: Hosting, customer: Websites) a website can switch
to a wildcard or an own certificate.

## Sources (`SITE_SSL` in `whp-site`)

| State | Files | Renewal |
| --- | --- | --- |
| `active` | `/etc/letsencrypt/live/<domain>/` | certbot timer |
| `wildcard` | `/etc/letsencrypt/live/whp-wildcard-<base>/` (`<base>` + `*.<base>`) | certbot timer, DNS-01 at Cloudflare |
| `custom` | `/etc/webhosting-panel/ssl/<domain>/` (`fullchain.pem` = certificate + CA bundle, `privkey.pem` 0600) | by hand; panel mails before expiry |
| `imported` | same folder, taken over by the Plesk migration | replaced by Let's Encrypt once DNS points here |

`whp-site show` reports `custom` and `wildcard` as `ssl: active` (a valid
certificate), so the rest of the panel keeps working; `whp-site cert <domain>|--all`
tells the source and the served files.

Commands:

```
whp-site ssl <domain> --custom-cert cert.pem --custom-key key.pem [--custom-chain chain.pem]
whp-site ssl <domain> --wildcard
whp-site ssl <domain> --letsencrypt
whp-site cert <domain> | --all
```

- Switching back to Let's Encrypt never drops a working own or wildcard
  certificate: when Let's Encrypt cannot issue yet, the command fails and the
  old certificate stays.
- The hourly retry (`whp-site ssl --pending`) never touches `custom` or `wildcard`.
- A subdomain website (`--parent`) uses the wildcard of its parent when every
  name of the subdomain website is one label below the parent.
- Removing a site deletes its own certificate and its wildcard (unless a
  subdomain website still uses it).

## Wildcard certificates

`installer/bin/whp-cert-dns <domain>` runs certbot with the
`python3-certbot-dns-cloudflare` plugin (installed by `installer/modules/55-nginx.sh`).
The Cloudflare API token is the one from **DNS & mail settings**; the agent
writes it to `/etc/webhosting-panel/ssl/cloudflare.ini` (0600) on every request
and on the daily check, so renewals keep working after the token changes.
The token needs **Zone:Read** and **DNS:Edit** for the zone. The panel checks
that the domain's zone is in the Cloudflare account before it asks.

## Expiry warnings

`php artisan certificates:check` (daily at 06:10) reads all certificates and
mails administrators and, for own certificates, the customer: 30, 14, 7, 3 and
1 day(s) before expiry, once per step (reset when a new certificate is
installed). Let's Encrypt certificates renew themselves; they only warn at 10,
5, 2 and 1 day(s), which means renewals keep failing.
