# WordPress quick switches

Part of the WordPress toolkit (`src/Switches`, registered by `WordPressServiceProvider`).
Per install, for admins (`/admin/wordpress/{install}`) and customers (`/wordpress/{install}`,
team area "WordPress & apps"), plus a compact block on the domain page (WordPress tab).

| Switch | How |
| --- | --- |
| Maintenance mode | mu-plugin `wp-content/mu-plugins/whp-maintenance.php` (agent `data/`), text in option `whp_maintenance`. Visitors get 503 + `Retry-After`; logged-in editors see the site. Page cache purged on every change. |
| Debug mode | `WP_DEBUG` on, `WP_DEBUG_DISPLAY` off, `WP_DEBUG_LOG` = `/var/www/vhosts/<domain>/logs/wp-debug[-<path>].log`. Switches itself off after 1/4/24/72 hours (`whp:wordpress:debug-expire`, every 5 min); admins may keep it on. |
| Debug log viewer | `wp.debug_log` merges `logs/php-error.log` (web requests: the FPM pool locks `error_log`) and `logs/wp-debug.log` (WP-cron, WP-CLI). Filter by level/text, live refresh, empty. A public `wp-content/debug.log` is flagged and can be deleted. |
| Search engines | option `blog_public`. |
| Clear cache | Nginx page cache, `wp cache flush` (object cache), known cache plugins (WP Rocket, W3TC, WP Super Cache, LiteSpeed, WP Fastest Cache, SiteGround, Breeze, Autoptimize, Elementor CSS), expired transients. |

Agent actions (`agent/whp_agent/actions/wp_switches.py`): `wp.switches`, `wp.maintenance_set`,
`wp.debug_set`, `wp.indexing_set`, `wp.cache_clear`, `wp.debug_log`, `wp.debug_log_clear`.
Last known state in table `wordpress_switches`. Smoke test: `tests/smoke.d/wp-switches.sh`.
