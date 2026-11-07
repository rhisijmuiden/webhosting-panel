# rhis/subdomains

Subdomains and website cloning for the Webhosting Panel.

## Subdomains

A customer adds `test.example.com` to their website `example.com` from the
domain page (Hosting & DNS tab, card "Subdomains"). A subdomain is **not** a
hosting package or subscription of its own: it belongs to the subscription of
its main website.

* Panel: a normal `websites` row with `parent_id` set (migration in this package).
  `Website::parent()`, `subdomains()`, `isSubdomain()` live in the core model.
* Server: `whp-site add test.example.com --parent example.com`. Own Linux
  user, PHP pool, vhost and Let's Encrypt certificate (a hacked test copy cannot
  touch the live site), but its files count towards the **parent's disk quota**
  (same project quota id) and it follows the parent when suspended. The parent
  cannot be removed while it has subdomains. No `www.` alias.
* DNS: no mail domain of its own; the dns-mail package adds its A/AAAA record
  to the main domain's zone (`DomainRecords::web()`).
* One level only (`a.example.com`, not `a.b.example.com`), reserved names
  (`www`, `mail`, `webmail`, `autodiscover`, ...) in `config/subdomains.php`.
* Limits: `WHP_SUBDOMAINS_MAX` (per domain, default unlimited),
  `WHP_SUBDOMAINS_CUSTOMER=false` turns customer management off.

## Cloning

"Clone website" copies a website into a new subdomain or another website of
the **same customer** (also back over the live site: "Copy to example.com").
Agent job `site.clone` (`agent/whp_agent/actions/site_clone.py`):

1. rsync of the document root as root, owned by the target user, caches left out, `--safe-links`.
2. Each WordPress/Joomla install: its database is dumped **with the site's own
   credentials** (never as MariaDB root, so a wp-config pointing at someone
   else's database cannot leak it), DEFINERs stripped, and loaded **as the new
   database's own user** into a new database (label `clone`).
3. WordPress: wp-config DB settings, `WP_HOME`/`COOKIE_DOMAIN`, paths in config
   files, `wp search-replace` of every address of the source (both schemes,
   JSON-escaped) and of the old document root, new salts, `blog_public=0`
   (option "hide from search engines"), cache flush.
   Joomla: database settings, `live_site`, `cookie_domain`, paths, new `secret`.
4. A failed clone drops the new databases and empties the target again. A
   successful re-clone drops the databases of the previous clone.

Replacing a target that already has a website needs `replace` and typing the
target's address. Not supported (refused with a reason): WordPress multisite,
databases on another server. Other sites (no CMS) are copied as files only.

Panel: `SiteClone` model (table `site_clones`), `CloneManager`; a clone into a
new subdomain waits until that subdomain is set up (`site-clone:tick`, every
minute, and the status page). Server smoke test: `tests/clone_smoke.py`.

## WordPress staging

"Make staging copy" (WordPress tab of a website, or `/websites/{id}/staging`)
clones a WordPress website into `staging.<domain>` with the clone tool above,
then marks the copy as staging (agent `wp.staging_prepare`): a mu-plugin
`wp-content/mu-plugins/whp-staging.php` holds back all outgoing mail
(`pre_wp_mail`; define `WHP_STAGING_SEND_MAIL` to allow it), keeps the copy out
of search engines and shows "STAGING" in the admin bar.

"Push to live" (agent job `wp.staging_push`, `agent/whp_agent/actions/wp_staging.py`):

1. Backup of the live install: all files (rsync with hard links to the previous
   backup) and a dump of its database made as the live site's own database
   user, in `/var/lib/webhosting-panel/staging/backups/<domain>/<stamp>/`.
   The last 3 are kept; each can be put back from the staging page
   (`wp.staging_restore`).
2. Files (optional): the staging install is synced onto live with `--delete`,
   except the live `wp-config.php`, caches and the staging mu-plugin. By default
   media that only exists on live (uploaded meanwhile) is kept. Paths and
   addresses in `.htaccess` and cache/firewall configs are changed to live.
3. Database (optional): the staging tables are dumped with the staging
   credentials and loaded into the live database as the live user, except the
   tables the customer keeps. For shops the order, customer, comment and form
   entry tables are pre-ticked (`suggested_keep`), so orders that came in on
   live meanwhile are not lost. WP-CLI search-replace turns the staging
   addresses and folder back into the live ones; the live search engine
   setting is put back.
4. The live front page is checked. If it worked before and answers 500+
   after, or any step fails, the backup is put back automatically.

Refused with a reason: different table prefixes, the copy using the live
database, multisite, a database on another server. Panel: `Staging` and
`StagingPush` models (tables `wp_stagings`, `wp_staging_pushes`),
`StagingManager`, `wp-staging:tick` every minute. Customers: team area
"WordPress & apps"; `WHP_WP_STAGING_CUSTOMER=false` turns it off for customers.
Server smoke test: `tests/staging_smoke.py`.
