# WordPress database cleanup

Admins (`/admin/wordpress/{install}/database-cleanup`) and customers
(`/wordpress/{install}/database-cleanup`, needs `WHP_WP_CUSTOMER_TOOLKIT`) can clean up
the database of a WordPress install. The page first shows a preview of what would be
removed; nothing changes until the button is pressed.

| Item | What is removed |
| --- | --- |
| revisions | post revisions, optionally keeping the newest 1–20 per post |
| auto_drafts | `auto-draft` posts |
| trashed_posts | posts/pages in the trash (through `wp_delete_post`, so media files go too) |
| spam_comments / trashed_comments | comments with status `spam` / `trash` and their meta |
| expired_transients | transients (and site transients) whose timeout passed |
| all_transients | every transient (optional, not ticked by default) |
| orphaned_meta | `postmeta` / `commentmeta` rows of posts/comments that no longer exist |
| optimize | `OPTIMIZE TABLE` on tables with free space and on the tables that were cleaned |

## How it runs

* Agent: `agent/whp_agent/actions/wp_dbclean.py` — `wp.dbclean_preview` and the job
  `wp.dbclean_run`. The SQL work is `agent/whp_agent/data/whp-dbclean.php`, piped to
  `wp eval-file -` as the site user (`--skip-plugins --skip-themes`), only on the
  tables of the install's own prefix.
* The job makes a toolkit snapshot first (database + plugins + themes, listed under
  *Snapshots* on the install page and restorable there), cleans, purges the page cache,
  and imports the snapshot database again when the site answered before but returns
  5xx afterwards.
* Panel: `packages/wordpress/src/DbCleanup/*` (own service provider, registered by the
  toolkit provider), routes `routes/dbclean-{admin,client}.php`, views
  `resources/views/dbclean`, translations `lang/dbclean/nl.json`, table `wp_db_cleanups`.
  `whp:wordpress:dbclean-collect` (every 5 minutes) stores outcomes nobody looked at.
* Tests: `agent/tests/test_wp_dbclean.py`, `panel/tests/Feature/WordPressDbCleanupTest.php`,
  and on a real install `tests/wp_dbclean_smoke.py` (via `tests/smoke.d/wp-dbclean.sh`).

No server preparation is needed: WP-CLI is already installed by the toolkit.
