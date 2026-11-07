<?php
// Managed by the Webhosting Panel installer (installer/modules/82-phpmyadmin.sh).
// Trades a one-time token from the panel (whp-agent db.sso) for a phpMyAdmin
// session. The token file holds a short-lived MariaDB login for one database;
// it is removed as soon as it is read, so a token works only once.
declare(strict_types=1);

function whp_sso_fail(): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>phpMyAdmin</title>'
        .'<p style="font-family:system-ui,sans-serif;margin:3rem auto;max-width:32rem">'
        .'This phpMyAdmin link has expired or was already used. Open phpMyAdmin again from the panel.<br><br>'
        .'Deze phpMyAdmin-link is verlopen of al gebruikt. Open phpMyAdmin opnieuw vanuit het panel.</p>'
        .'<p style="font-family:system-ui,sans-serif;margin:0 auto;max-width:32rem"><a href="/">Panel</a></p>';
    exit;
}

$token = $_GET['token'] ?? '';
if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    whp_sso_fail();
}
$file = '/run/whp-pma/'.$token.'.json';
$raw = @file_get_contents($file);
@unlink($file);
$login = is_string($raw) ? json_decode($raw, true) : null;
if (! is_array($login) || (int) ($login['expires'] ?? 0) < time()
    || preg_match('/^whp_pma_[a-f0-9]{12}$/', (string) ($login['user'] ?? '')) !== 1
    || preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) ($login['database'] ?? '')) !== 1) {
    whp_sso_fail();
}

session_set_cookie_params(['lifetime' => 0, 'path' => '/phpmyadmin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_name('WHPSignon');
session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = $login['user'];
$_SESSION['PMA_single_signon_password'] = (string) $login['password'];
$_SESSION['PMA_single_signon_cfgupdate'] = ['only_db' => $login['database']];
session_write_close();

$lang = in_array($login['lang'] ?? 'en', ['en', 'nl'], true) ? $login['lang'] : 'en';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: index.php?'.http_build_query(['route' => '/database/structure', 'db' => $login['database'], 'lang' => $lang]));
