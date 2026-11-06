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
