<?php
/**
 * Plugin Name: Maintenance mode (Dekwerk)
 * Description: Visitors see a maintenance page while this file exists. Logged-in editors and administrators see the website as usual. Managed by the hosting panel: switch it off there (WordPress > Quick switches), or delete this file.
 * Version: 1.0
 */

defined('ABSPATH') || exit;

/** The page visitors get: 503 with Retry-After, so search engines come back later and keep the site in their index. */
add_action('template_redirect', static function () {
    if (is_user_logged_in() && current_user_can('edit_posts')) {
        return;
    }
    $o = get_option('whp_maintenance');
    $o = is_array($o) ? $o : [];
    $title = isset($o['title']) && is_string($o['title']) && $o['title'] !== '' ? $o['title'] : 'We will be back soon';
    $message = isset($o['message']) && is_string($o['message']) && $o['message'] !== ''
        ? $o['message'] : 'This website is undergoing maintenance. Please try again in a little while.';
    $lang = isset($o['lang']) && is_string($o['lang']) && preg_match('/^[a-z]{2}$/', $o['lang']) ? $o['lang'] : 'en';
    $retry = isset($o['retry_after']) ? max(60, min(86400, (int) $o['retry_after'])) : 3600;

    status_header(503);
    nocache_headers();
    header('Retry-After: '.$retry);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $name = get_bloginfo('name');
    ?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html($title.($name !== '' ? ' · '.$name : '')); ?></title>
<style>
:root { --bg: #f6f5f2; --card: #fff; --ink: #1d1f24; --muted: #646b78; --line: #e6e3dc; --accent: #e8664f; }
@media (prefers-color-scheme: dark) { :root { --bg: #13151a; --card: #1c1f26; --ink: #eef0f3; --muted: #9aa2b1; --line: #2b2f38; } }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink);
  font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 520px; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 40px 32px; text-align: center; }
.icon { width: 56px; height: 56px; margin: 0 auto 20px; border-radius: 16px; display: grid; place-items: center; background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); }
h1 { margin: 0 0 12px; font-size: 26px; line-height: 1.25; }
p { margin: 0; color: var(--muted); }
.site { margin-top: 24px; font-size: 14px; color: var(--muted); }
</style>
</head>
<body>
<main>
  <div class="icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
  <h1><?php echo esc_html($title); ?></h1>
  <p><?php echo nl2br(esc_html($message)); ?></p>
  <?php if ($name !== '') { ?><div class="site"><?php echo esc_html($name); ?></div><?php } ?>
</main>
</body>
</html><?php
    exit;
}, 0);

/** A reminder in the admin bar, so nobody forgets the site is closed for visitors. */
add_action('admin_bar_menu', static function ($bar) {
    if (! current_user_can('edit_posts')) {
        return;
    }
    $bar->add_node([
        'id' => 'whp-maintenance',
        'title' => '<span style="background:#e8664f;color:#fff;border-radius:4px;padding:2px 8px">Maintenance mode</span>',
        'meta' => ['title' => 'Visitors see the maintenance page. Switch it off in the hosting panel.'],
    ]);
}, 100);
