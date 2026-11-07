# Smart WordPress updates

Scheduled WordPress updates in the maintenance window, checked with screenshots.
Admin: **WordPress > Smart updates** (`/admin/wordpress/smart-updates`).
Customers: **WordPress > Smart updates** (`/wordpress/smart-updates`) and a card on
the WordPress tab of the domain page.

## What happens per site

1. **Plan.** The agent asks WordPress what can be updated and picks what the mode allows.
2. **Screenshots before.** Every page (home page plus up to 4 extra pages) is
   photographed on desktop (1366 px) and mobile (390 px), twice, a few seconds
   apart. Blocks that differ between those two shots are "moving content"
   (sliders, dates, ads) and are left out of the comparison.
3. **Restore point and update.** The normal toolkit update: database, plugins and
   themes are saved (`wp.update`, snapshot), then core, plugins, themes and
   translations are updated. A site that answers HTTP 500+ afterwards is rolled
   back at once (health check).
4. **Compare.** The pages are photographed again and compared in 16x16 px blocks.
   The score is the share of (non-moving) blocks that changed. A page that went
   blank scores 100%.
5. **Keep or roll back.** Above the threshold (default 10%) the snapshot is put
   back and a last screenshot shows that the site looks as before.

The customer gets a mail (per site: after every update, only problems, or never),
the admins get a mail about rollbacks and failures.

## Modes (per site, with a panel default)

| Mode | What it does |
|:--|:--|
| All updates | Core, all plugins, all themes and translations. |
| Security updates only (default) | The newest release of the installed core branch (`wp core update --minor`) plus plugins and themes with an open vulnerability from the security scan (`security_findings`). |
| Notify only | Nothing is changed; a mail when updates are waiting (once per set of updates). |
| Off | No scheduled updates and no mails. |

"Update now" on a site runs an *all updates* smart update immediately, outside the window.

## When

The maintenance window of server management (default 03:00-04:00 every night).
Smart updates start 5 minutes after the window opens and only after the server's
own maintenance session (OS updates, reboot) is done; no new update starts in the
last 15 minutes. With the server window off, the own window from
`config/smart-updates.php` (02:00-05:00) is used. At most *concurrency* sites
(default 2) are updated at the same time; sites that did not fit go first the next night.

## Server parts

- Installer module `58-wp-visual.sh`: `chromium-headless-shell` (or `chromium`),
  `python3-pil`, fonts, and the system user `whp-shot` (no login).
- `agent/whp_agent/shot_worker.py` drives Chromium over the DevTools pipe
  (`--remote-debugging-pipe`): viewport, wait for load, animations off, full page
  up to 5000 px. It runs as `whp-shot` in a sandboxed transient unit
  (`ProtectSystem=strict`, `PrivateTmp`, `NoNewPrivileges`, 1.5 GB memory, 100 s)
  and gets its code on stdin. The site is asked of this server itself
  (host-resolver rules), so DNS and CDN are not involved.
- Agent actions (`actions/wp_smart.py`): `wp.smart_update` (job), `wp.smart_image`,
  `wp.smart_forget`, `wp.smart_check`. Screenshots wait in
  `/var/lib/webhosting-panel/wordpress/visual/<run>/` until the panel copied them
  into its own storage (`storage/app/private/wp-smart-updates/<run>/`); leftovers
  are removed after 14 days. The panel keeps history and screenshots 90 days.

Without Chromium or Pillow the updates still run, guarded by the health check only;
the settings tab shows what is missing.

## Panel code

Inside `packages/wordpress` (own files, so the toolkit stays untouched):
`src/SmartUpdates/*` (provider, `SmartUpdater`, models `UpdatePolicy`/`UpdateRun`,
controllers, mail, fake agent answers), `routes/smart-updates-{admin,client}.php`,
`resources/views/smart-updates/*`, `lang/smart-updates/nl.json`, migration
`2026_11_07_031500` (tables `wp_update_policies`, `wp_update_runs`), config
`config/smart-updates.php` (key `wp-smart-updates`). Scheduler:
`whp:wordpress:smart-updates` every minute.

Tests: `panel/tests/Feature/WordPressSmartUpdatesTest.php`,
`agent/tests/test_wp_smart.py`, Debian smoke test `tests/wp_smart_smoke.py`
(real Chromium: a good update is kept, a visual change is rolled back).
