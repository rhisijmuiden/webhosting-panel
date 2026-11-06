# Server installer (Debian 13)

*Nederlands: [installer.nl.md](installer.nl.md)*

The installer turns a fresh **Debian 13 (Trixie)** server (bare metal or VM) into a
secure, fast hosting server for the Webhosting Panel. It is safe to run again:
managed files are rewritten, data and secrets are kept.

## Install

```bash
# production channel (branch main)
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash

# test channel (branch test), no questions
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/test/installer/bootstrap.sh \
  | sudo bash -s -- --channel test -y --hostname server1.example.com --email you@example.com
```

Or from a checkout: `sudo installer/install.sh`. Run `installer/install.sh --help` for all options.
Messages are in English, or Dutch with `--lang nl` (or a `nl_*` locale).

Requirements: Debian 13, amd64 or arm64, 2 GB RAM (4 GB with ClamAV), 20 GB disk,
a public IPv4 address and an A record for the hostname (for Let's Encrypt).

## What is installed

| Component | Version / source | Notes |
| --- | --- | --- |
| Nginx | Debian 13 | FastCGI page cache, HTTP/2, HTTP/3 (QUIC), Brotli + gzip, TLS 1.2/1.3 only, unknown hosts get no answer |
| PHP-FPM | 8.3, 8.4, 8.5 (Sury) | Side by side; every site has its own user, pool and socket, OPcache + JIT |
| MariaDB | 11.8 LTS (Debian) | Localhost only, root via unix socket only, buffer pool sized to RAM |
| Valkey | 8 (Debian) | Redis compatible, localhost only, **ACLs with passwords** (customer PHP runs on the same host) |
| Postfix | Debian | Optional relay host (verified TLS + SASL), submission 587 and 465, texthash maps |
| Dovecot | 2.4 (Debian) | IMAPS 993, ManageSieve 4190, Sieve, LMTP; plain IMAP 143 on loopback only |
| Rspamd | upstream repo | Spam filter with greylisting, Bayes in Valkey, DKIM/ARC signing, DMARC |
| ClamAV | Debian | Mail scanning via Rspamd, daily webroot scan with php-malware-finder YARA rules |
| CrowdSec | upstream repo | Detects SSH/Nginx/WordPress/Postfix/Dovecot attacks, blocks in nftables, community blocklist |
| nftables | Debian | Default drop, only hosting ports open, SSH brute force brake |
| Unbound | Debian | Local validating resolver (needed for DNS blocklists) |
| Certbot | Debian | Let's Encrypt for the hostname and every site, automatic renewal |

Open ports: SSH, 80, 443 (TCP + UDP for HTTP/3), 8443 (panel), 25, 465, 587, 993, 4190.

Also: unattended security upgrades (Debian, Sury, Rspamd, CrowdSec), kernel tuning
(BBR, fq, SYN cookies, hardening), SSH hardening (key only, only when a key is present),
bounded journald, English and Dutch locales, Composer.

## Websites: `whp-site`

The panel uses this tool through its agent; it works by hand too.

```bash
whp-site add example.com                       # PHP 8.4, www alias, page cache, Let's Encrypt
whp-site add shop.example.com --php 8.3 --profile wordpress
whp-site add app.example.com --profile laravel  # code in app/, served from app/public
whp-site php example.com 8.5                    # switch PHP version
whp-site cache example.com off
whp-site quota example.com 5120                 # 5 GB hard limit
whp-site ssl example.com                        # retry Let's Encrypt now
whp-site list --json
whp-site suspend example.com     # 503 page, PHP stopped, login locked (unsuspend undoes it)
whp-site remove example.com
```

Every site lives in `/var/www/vhosts/<domain>/` with `public/` (or `app/public/`),
`logs/`, `tmp/` and `sessions/`, runs as its own Linux user and is limited by
`open_basedir`. Let's Encrypt is requested automatically; if DNS is not ready yet the
site stays on HTTP and an hourly timer retries until the certificate is issued.

**Page cache**: anonymous GET requests are cached for 10 minutes. Logged-in WordPress
and WooCommerce users, carts, admin pages, POST requests and query strings are never
cached; responses that set a cookie are not cached either. The `X-Cache` header shows
`HIT`, `MISS` or `BYPASS`.

**Laravel**: profile `laravel` points the docroot to `app/public`, turns the page cache
off and allows `proc_open`. Composer is installed.

## Disk quotas

Hard limits per website need project quotas on the filesystem that holds `/var/www`.
A mounted root filesystem cannot be switched to project quotas, so the recommended
setup is a separate data disk:

```bash
installer/install.sh --quota-device /dev/sdb   # EMPTY disk: formatted as XFS with prjquota, mounted on /var/www
```

The installer refuses to format a disk that has partitions or a filesystem. Without
project quotas the limit is stored and reported, but not enforced (the installer
warns; `--require-quota` makes it stop instead).

## Updates

An update from the panel (Info → Update, or switching channel) runs
`agent/scripts/update.sh`. After checking out the new code it runs
`installer/install.sh --upgrade` in its own transient systemd unit:

- every module runs again with the saved answers from `/etc/webhosting-panel/installer.env`,
  so new packages, config templates, systemd units and directories reach existing servers;
- no `apt full-upgrade` (unattended-upgrades handles package updates), and services are
  reloaded instead of restarted where they support it, so sites and mail keep running;
- numbered one-off server migrations in `installer/migrations/` run once per server
  (see [installer/migrations/README.md](../installer/migrations/README.md)).

Nothing has to be run by hand after an update. A failing step stops the update and the
code is rolled back.

## Other tools

| Command | What |
| --- | --- |
| `whp-status [--json]` | State of every managed service |
| `whp-cert <domain> [names]` | Request a Let's Encrypt certificate (webroot) |
| `whp-malware-scan <domain>\|--all` | ClamAV + YARA scan, JSON report in `/var/lib/webhosting-panel/malware/` |

## Files

| Path | What |
| --- | --- |
| `/opt/webhosting-panel` | Code (git clone, channel branch) |
| `/etc/webhosting-panel/installer.env` | Answers given to the installer |
| `/etc/webhosting-panel/secrets.env` | Generated passwords (root only) |
| `/etc/webhosting-panel/panel.env` | Laravel `.env` of the panel |
| `/etc/webhosting-panel/install-summary.txt` | Summary of the last run |
| `/var/log/webhosting-panel/install.log` | Full install log |

See [architecture/server-layout.md](architecture/server-layout.md) for the conventions
other components rely on.

## Testing

Every push runs ShellCheck and a full install in a Debian 13 container with systemd
(`.github/workflows/installer.yml`), followed by `tests/smoke.sh`: services, config
tests, security defaults, PHP switching, page cache, Laravel docroot, mail delivery,
spam rejection and a second (idempotent) run.
