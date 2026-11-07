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
5. **Migration wizard, per domain.** Copying runs for all chosen domains at
   once; afterwards each domain gets its own wizard (**Start wizard** on the
   source list, or **Migration wizard** on the domain's migration page,
   `/admin/migration/items/{item}/wizard`). It walks through:
   - **Website**: what was copied, last copy time, **Test the website** (the
     panel requests `https://<domain>/` from this server via `CURLOPT_RESOLVE`),
     the hosts-file line and **Fix WordPress settings** for WordPress;
   - **Databases** and **Mailboxes**: per item copied/failed, new passwords.
     The mail step also asks where mail goes: *to this server*, or *keep it where
     it is* (mail provider `external`, so the switch leaves MX/SPF/autodiscover
     alone). When Plesk's MX points at Microsoft 365, Google and the like the
     wizard says so;
   - **SSL**: imported certificate now, Let's Encrypt after the switch;
   - **DNS records**: a comparison of what Cloudflare has now with what this
     server needs (`SyncPlanner` with take-over) plus the domain's own records
     from Plesk (`DnsCutover::importable`). Every change (add, change, remove)
     has a checkbox; changes that replace a record of an external service
     (Microsoft 365, Google, Mailgun, …) or where Cloudflare differs from Plesk
     start unticked. Records that are already right or that the switch never
     touches are listed separately. Without a zone the step can add the domain
     to Cloudflare (zone + record scan, nothing switched) or fall back to the
     zone file;
   - **Final check**: lists what blocks the switch (unconfirmed steps, failed
     copies, copy not finished), the chosen changes and the ones kept. The
     switch needs a tick and the domain name typed. **Sync again** right before.
   Nothing is sent to Cloudflare before the final check. The switch
   (`Wizard\DnsSwitch`) recomputes the comparison and refuses when Cloudflare
   changed since the choice, saves a snapshot of the domain's records
   (`migration_dns_snapshots`) and applies only the ticked lines in one atomic
   Cloudflare batch. **Put old DNS back** restores the snapshot: changed records
   get their old value, removed ones return, records the switch added are
   deleted, DNS management is turned off again and the domain goes back to
   *Copied*. Records added by hand afterwards stay.
6. **Switch DNS (without the wizard).** The older one-click switch
   (`items.cutover`) still exists: it adds the domain's own Plesk records and lets
   the panel take over web and mail records. Without Cloudflare it gives a BIND
   zone file. Let's Encrypt is requested right away and retried by `whp-cert-retry`.
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
