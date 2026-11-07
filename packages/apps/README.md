# rhis/apps: one-click app installer

Admin (`/admin/apps`, menu Hosting › App installer) and customers (`/apps`)
can put a ready-to-use app into a website folder: files, a MariaDB database
with its own user, the configuration and an admin account.

| App | Folder | Languages | Notes |
| --- | --- | --- | --- |
| WordPress | root or subfolder | EN, NL | WP-CLI, `/%postname%/` permalinks, `DISALLOW_FILE_EDIT`, random table prefix |
| Joomla | root or subfolder | EN | Joomla CLI installer, latest stable from GitHub, installation folder removed |
| WooCommerce | root or subfolder | EN, NL | WordPress + WooCommerce plugin; NL: Dutch language pack, EUR, shop country NL |
| Joomla | root or subfolder | EN | page cache switched off (random session cookie name) |
| Nextcloud | root only | EN, NL | data folder outside the web root (`<site>/nextcloud-data`), page cache switched off, CalDAV/CardDAV redirects |
| Piwigo (photos) | root or subfolder | EN, NL | its own installer run from the CLI, `local/config` closed, config 0600 |
| MediaWiki | root or subfolder | EN, NL | `maintenance/run.php install`, LocalSettings.php 0600, maintenance/includes/cache closed |
| DokuWiki | root or subfolder | EN, NL | no database; bcrypt admin in `conf/users.auth.php`, ACL on, registration off, install.php removed, data/conf closed |
| phpBB | root or subfolder | EN | `install/phpbbcli.php` with a private YAML file, install folder removed, mail via the local Postfix |
| FreshRSS | root or subfolder | EN, NL | feeds refreshed every 20 min by a systemd timer as the site user (`whp-app-<id>.timer`) |

Apps with their own login sessions switch the page cache of the site off, so a
logged-in page is never served to someone else.

Maintenance policy (security)

* The agent checks the latest release of every app (GitHub releases,
  wordpress.org, releases.wikimedia.org, version.phpbb.com, the DokuWiki
  download) and caches it for 6 hours (`/var/lib/webhosting-panel/apps/releases.json`).
* An app with no release in `WHP_APPS_MAX_RELEASE_AGE_DAYS` (default 365) or
  whose version is end of life is not offered: customers do not see it, the
  admin sees it greyed out with the reason, and the agent refuses to install it.
* Installed apps show the version on disk and an "Update" badge when a newer
  release is out. WordPress/WooCommerce update through the WordPress tools
  (with a snapshot); the other apps update with their own built-in updater.

How it works

1. The form posts to the panel, which calls the agent's `apps.install`
   (see `agent/whp_agent/actions/apps.py`). The agent checks the folder is
   empty and starts a job; the panel stores an `app_installations` row with
   status `installing` and the job id. The admin password is passed on once
   and never stored by the panel or the agent.
2. The status page and the list follow the job (`job.status`). On success the
   row gets the version, URLs and database name; on failure the agent has
   already rolled everything back (files, database, Nginx snippet).
3. Remove calls `apps.remove`: files, database and Nginx snippet are deleted,
   a root install gets the placeholder page back.

Rules

* Customers only see and use their own active websites.
* Each app (except DokuWiki) uses one database; the customer's package `max_databases` is the
  limit (counted over all databases of the customer's websites, `site_databases`).
* `WHP_APPS_CUSTOMER_INSTALL=false` in `panel.env` hides the page for customers.

WordPress plugin sets

* Admin › App installer › **WordPress plugin sets** (`/admin/apps/plugin-sets`):
  named lists of plugins and themes (wordpress.org slugs or https links to a
  `.zip`), per item "switch on", one theme to use, and two clean-ups (example
  post/page, unused Twenty… themes). Names come from the wordpress.org API on
  save; an unknown slug gives a warning.
* A set can be the default of one or more packages (`wp_plugin_set_packages`,
  one set per package): the install form preselects it for websites of that
  package. Customers see the sets marked "Customers can choose this set" plus
  the default of their own package.
* The set travels with `apps.install` as `plugin_set` (WordPress and
  WooCommerce only) and is applied inside the same agent job right after
  WordPress (`agent/whp_agent/wp_sets.py`, WP-CLI as the site user, Dutch
  language packs for a Dutch site). A failing item never rolls WordPress back:
  it is reported in `app_installations.plugin_set_report` and on the app page.

 a recipe class in `agent/whp_agent/actions/apps.py` (added
to `RECIPES`), a card in `config/apps.php` and its texts in `lang/nl.json`.
