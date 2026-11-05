# Server layout (set up by the installer)

Conventions the panel, agent, mail, backup and CRM components can rely on.
Complements [interfaces.md](interfaces.md).

## Websites

| What | Value |
| --- | --- |
| Root | `/var/www/vhosts/<domain>/` (`public/` or `app/public/`, `logs/`, `tmp/`, `sessions/`) |
| State | `/var/lib/webhosting-panel/sites/<domain>.env` |
| Linux user | `s_<domain with . and - as _>` (long names: shortened + hash) |
| PHP-FPM pool | `/etc/php/<ver>/fpm/pool.d/site-<id>.conf`, socket `/run/php/site-<id>.sock` |
| Nginx vhost | `/etc/nginx/sites-available/whp-<domain>.conf`, extra snippets in `/etc/nginx/whp/sites/<domain>/*.conf` |
| Certificate | `/etc/letsencrypt/live/<domain>/` |
| Tool | `whp-site` (all subcommands accept `--json`: JSON on stdout, progress on stderr, exit code != 0 on error) |

Site JSON: `{domain, user, php, aliases[], cache, profile, root, docroot, ssl: active|pending|off, socket, created, disk: {used_mb, quota_mb, enforced, mode}}`.

## Mail

| What | Value |
| --- | --- |
| Postfix maps (texthash, `postfix reload` applies) | `/etc/postfix/whp/virtual_domains`, `virtual_mailboxes`, `virtual_aliases`, `sender_login` |
| Mailboxes | Dovecot passwd-file `/etc/dovecot/users`, Maildir in `/var/vmail/<domain>/<user>/`, `vmail` 5000:5000 |
| Sending | submission 587 (STARTTLS) and 465 (TLS); a login may only send as the addresses listed for it in `sender_login` |
| IMAP | 993 TLS, 143 on 127.0.0.1/::1 only (webmail), ManageSieve 4190 |
| Rspamd | milter 127.0.0.1:11332, controller 127.0.0.1:11334 (`RSPAMD_CONTROLLER_PASS`), Valkey db 2 |
| DKIM | `/var/lib/rspamd/dkim/<domain>.whp.key` (selector `whp`), signs when the key exists |
| Relay | optional, `relayhost` + `/etc/postfix/sasl_passwd` |

## Services and secrets

| What | Value |
| --- | --- |
| Valkey | 127.0.0.1:6379, ACL users `default` (`VALKEY_DEFAULT_PASS`, Rspamd) and `whp` (`VALKEY_WHP_PASS`, panel db 1) |
| MariaDB | 127.0.0.1:3306, root via unix socket, `whp_panel` (`PANEL_DB_PASS`) |
| Secrets | `/etc/webhosting-panel/secrets.env` (0600, KEY=value) |
| Firewall | `table inet whp`; extra rules in `/etc/nftables.d/*.nft`. To open a port add to the sets: `add element inet whp tcp_open { 3306 }`; to block: `add element inet whp blocked_v4 { 192.0.2.1 }`. `systemctl reload nftables` replaces only this table |
| Quota mode | `WHP_QUOTA_MODE` in `/etc/webhosting-panel/installer.env`: `xfs`, `ext4` or `none` |
| Component installers | run in this order when present: `agent/install.sh`, `panel/deploy/install.sh`, `webmail/snappymail/install.sh`, `backup/install.sh` |
