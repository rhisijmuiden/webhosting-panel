# WordPress security hardening

One-click security measures per WordPress install, for admins
(`/admin/wordpress-hardening/{install}`) and customers (`/wordpress-hardening/{install}`).
The page opens from the card on the WordPress install page and from the
WordPress tab of the domain page. Every measure can be switched off again.

| Key | What it does | Where |
| --- | --- | --- |
| `file_edit` | `DISALLOW_FILE_EDIT` in wp-config.php (theme/plugin editor off) | wp-config.php |
| `php_folders` | No PHP from `wp-content/uploads`, `upgrade`, `cache`, `wp-includes/*.php`, `wp-admin/includes/` | Nginx |
| `wp_config` | wp-config.php mode 0600 (previous mode is restored when switched off) and never served | file mode + Nginx |
| `hide_version` | No generator tag, `?ver=` of core files hashed, readme.html/license.txt blocked | mu-plugin + Nginx |
| `user_enum` | `/?author=N` refused outside wp-admin, users REST endpoint and users sitemap hidden for visitors | mu-plugin + Nginx |
| salts | Renew the eight keys/salts (logs everyone out); the last renewal can be undone | wp-config.php |

Always on for every site (shown, not switchable): no directory listing
(Nginx `autoindex` off), no PHP in `/wp-content/uploads` of a root install,
`xmlrpc.php` blocked, hidden/backup files blocked (`snippets/deny.conf`).

## Server side

Agent actions `wphard.status`, `wphard.apply` (`{"domain", "path", "measures": {key: bool}}`,
keys not given stay as they are) and `wphard.salts` (`{"undo": true}` puts the previous keys back),
in `agent/whp_agent/actions/wp_hardening.py`.

- Nginx: `/etc/nginx/whp/sites/<domain>/25-wp-hardening.conf`, rules of all installs of the
  site; `nginx -t` before the reload, the old file is put back when Nginx refuses.
  The Redirects & access rules (`30-web-rules.conf`) are a separate file.
- WordPress part: `wp-content/mu-plugins/whp-hardening.php`, written by the site user,
  removed when no mu-plugin measure is on.
- State: `/var/lib/webhosting-panel/wp-hardening/<domain>.json` (Nginx measures per install,
  previous wp-config mode, salt renewal time) and `<domain>/<key>.salts.json` (0600, previous salts).
- wp-config.php is edited as text by root (`migration/wordpress_fix`): no symlinks, only a
  regular file of the site user, owner and mode kept. No WordPress PHP runs.

Status is read from the files themselves, so a customer who changes wp-config.php by hand
sees the real state. The panel keeps the last answer in `wp_hardening_states` for the card.

## Panel

`App\Providers\WpHardeningServiceProvider`, `App\Services\WpHardening\Hardening`
(`apply()`, `hardenAll()`, `salts()`: also for bulk actions), controllers in
`App\Http\Controllers\WpHardening`, routes `routes/{admin,client}-wp-hardening.php`,
views `resources/views/wp-hardening`, translations `lang/features/wp-hardening`,
CSS `public/assets/wp-hardening.css`. Team members need the "Website settings" area.
`WHP_WP_HARDENING_CUSTOMER=false` hides the feature from customers (it is also hidden
when the customer WordPress toolkit is off).

Tests: `panel/tests/Feature/WpHardeningTest.php`, `agent/tests/test_wp_hardening_actions.py`,
smoke test `tests/smoke.d/wp-hardening.sh` (real Nginx on Debian 13 in CI).
