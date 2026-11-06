# Server migration (Plesk)

Admin menu **Server → Migration** moves websites, databases, mailboxes,
certificates and DNS from a Plesk server to this panel. The old server keeps
running until you switch DNS per domain, so visitors notice nothing.

## Steps

1. **Connect.** The panel has its own SSH key (`/etc/webhosting-panel/migration/id_ed25519`).
   Add the public key, shown on the migration page, to `/root/.ssh/authorized_keys`
   on the Plesk server, then add the server in the panel. The first test
   records the Plesk host key; later connections refuse any other key. Remove
   the line from `authorized_keys` when the migration is done.
2. **Read.** The agent reads the `psa` database over SSH (login from
   `/etc/psa/.psa.shadow`): subscriptions, owners, domains, subdomains,
   aliases, document roots, PHP versions, MySQL databases and their users,
   mailboxes, forwarders, DNS records and certificates.
3. **Choose.** Pick domains, the customer they go to (new from the Plesk owner,
   or an existing customer) and what to copy. Domains of one Plesk
   subscription end up with the same customer.
4. **Copy** (two domains at a time, `WHP_MIGRATION_PARALLEL`):
   - the customer, website (`whp-site add`, with the Plesk aliases) and mail domain
     are created. The mail domain starts with **DNS not managed**, so nothing in
     Cloudflare changes yet;
   - mailboxes get their old password: plain text from Plesk's `mail_auth_view`
     is hashed with Argon2id by the panel (it is never stored or written to a job
     file), or the stored `$6$` hash is used as `{SHA512-CRYPT}`;
   - files: `rsync` of the Plesk web directory (`httpdocs`, or the subdomain
     directory) into the site's `public/` (or `app/` when the docroot was deeper,
     e.g. Laravel `httpdocs/public` becomes `app/public`), owned by the site user,
     directories 0755 and files 0644 (`--perms`, so the agent's umask does not
     make them unreadable for Nginx);
   - databases: `mysqldump --single-transaction` streamed over SSH straight into
     MariaDB (DEFINER clauses and MySQL 8 collations are rewritten). Database
     users get their original `mysql_native_password` hash, so `wp-config.php`
     and other configs keep working. When the hash cannot be copied, a new
     password is shown on the migration page;
   - WordPress (every install found in the docroot): `wp-config.php` gets
     `DB_HOST` localhost and, when a database user got a new password, that
     password; the old Plesk docroot (`/var/www/vhosts/<domain>/httpdocs`) is
     replaced by the new one in `wp-config.php`, `.htaccess`, `.user.ini`,
     `php.ini`, `wordfence-waf.php`, cache configs and `wp-content/wflogs/*.php`,
     and in the database with `wp search-replace --precise --skip-columns=guid`
     (WP-CLI as the site user). Runs again after every sync; the button
     **Fix WordPress settings** (`migrate.wordpress_fix`) runs it on its own;
   - mail: `rsync` of every Maildir (Dovecot uidlist kept, indexes rebuilt);
   - SSL: the certificate Plesk uses is installed with
     `whp-site ssl <domain> --import-cert … --import-key …` (state `imported`)
     until Let's Encrypt can issue one after the switch.
5. **Check and sync.** Test the site through your hosts file. **Sync again**
   copies only what changed (rsync delta, fresh database dump, mail mirror).
6. **Switch DNS.** Adds the domain's own records from Plesk (verification TXT,
   extra CNAMEs, SRV, …) to Cloudflare, turns DNS management on and lets the
   panel write web, MX, SPF, DKIM, DMARC and autodiscover records. Records that
   pointed at the old server and Plesk service names are left out (listed on the
   page). Without Cloudflare the page shows a BIND zone file to load at the DNS
   host. Let's Encrypt is requested right away and retried by `whp-cert-retry`.
7. **Late mail.** After the switch, **Fetch late mail** copies messages that
   still arrived on Plesk (only new files, nothing is deleted here).
8. **Finish.** Removes the stored temporary database passwords.

Progress and the log of every step are on **Migration → Status and log**; the
log is stored per domain (`migration_items.log`) and stays after the migration.

## Not migrated (shown as a note on the domain)

PostgreSQL databases, databases on a remote database server, mailboxes that
both keep a copy and forward (the forward is listed to set up again), cron
jobs, FTP accounts and Plesk extensions. Old PHP versions are moved up to PHP
8.3 with a note to test the site.

## Pieces (for the customer migration assistant and the WordPress helper)

| Piece | What |
| --- | --- |
| `agent/whp_agent/migration/ssh.py` | SSH connection: own key, own known_hosts, multiplexed |
| `agent/whp_agent/migration/transfer.py` | Source independent: rsync files and Maildirs, stream a dump into MariaDB, database users, certificate import |
| `agent/whp_agent/migration/plesk.py` | Plesk source: inventory, paths, credentials, dumps, certificates |
| `packages/migration` (`Rhis\Migration\`) | Panel: sources, items, `SitePlan` (inventory → website), `Migrator`, `DnsCutover` |

A new source (DirectAdmin, cPanel, a single WordPress site) adds a module next
to `plesk.py` with the same functions (`detect`, `inventory`, `domain_row`,
`mail_credentials`, `mysql_user_hash`, `dump_command`, `certificate`,
`MAILNAMES`) and registers it in `SOURCES` in `actions/migrate.py`. The panel
side works on the inventory format below and needs no change.

### Inventory format

```json
{
  "source": {"type": "plesk", "version": "18.0.74", "hostname": "plesk.example.com"},
  "server_ips": ["203.0.113.5"],
  "owners": [{"id": "3", "login": "klant", "kind": "client", "name": "", "company": "", "email": "", "phone": ""}],
  "domains": [{
    "name": "example.com", "kind": "site|forward|none", "is_subdomain": false, "parent": null,
    "subscription": "example.com", "owner_id": "3", "active": true, "size_mb": 412, "php": "8.2",
    "web": {"root": "/var/www/vhosts/example.com/httpdocs", "base": "/var/www/vhosts/example.com/httpdocs",
            "sub": "", "vhost": "example.com", "system_user": "klant", "app": "wordpress|laravel|php"},
    "aliases": [{"name": "example.nl", "web": true, "mail": false}],
    "databases": [{"name": "wp_example", "type": "mysql", "server": "local", "users": ["wp_example"]}],
    "mailboxes": [{"local": "info", "quota_mb": 1024, "size_mb": 230, "password": "plain|hash|none", "aliases": [], "forward": []}],
    "forwarders": [{"local": "sales", "targets": ["someone@example.org"]}],
    "dns": [{"type": "TXT", "name": "example.com", "content": "google-site-verification=…"}],
    "ssl": {"name": "Lets Encrypt example.com", "lets_encrypt": true},
    "notes": []
  }],
  "totals": {"domains": 1, "sites": 1, "databases": 1, "mailboxes": 1, "size_mb": 412},
  "warnings": []
}
```
