# rhis/apps: one-click app installer

Admin (`/admin/apps`, menu Hosting › App installer) and customers (`/apps`)
can put a ready-to-use app into a website folder: files, a MariaDB database
with its own user, the configuration and an admin account.

| App | Folder | Languages | Notes |
| --- | --- | --- | --- |
| WordPress | root or subfolder | EN, NL | WP-CLI, `/%postname%/` permalinks, `DISALLOW_FILE_EDIT`, random table prefix |
| Joomla | root or subfolder | EN | Joomla CLI installer, latest stable from GitHub, installation folder removed |
| Nextcloud | root only | EN, NL | data folder outside the web root (`<site>/nextcloud-data`), page cache switched off, CalDAV/CardDAV redirects |

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
* Each app uses one database; the customer's package `max_databases` is the
  limit (counted over app installs).
* `WHP_APPS_CUSTOMER_INSTALL=false` in `panel.env` hides the page for customers.

Adding an app: a recipe class in `agent/whp_agent/actions/apps.py` (added
to `RECIPES`), a card in `config/apps.php` and its texts in `lang/nl.json`.
