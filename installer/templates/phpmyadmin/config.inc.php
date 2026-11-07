<?php
// Managed by the Webhosting Panel installer (installer/modules/82-phpmyadmin.sh).
// Sign-on only: the panel hands out one-time logins (whp-agent db.sso, whp-sso.php).
declare(strict_types=1);

$cfg['blowfish_secret'] = '{{PMA_BLOWFISH}}';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'WHPSignon';
$cfg['Servers'][$i]['SignonCookieParams'] = [
    'lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
];
// Without a valid sign-on, and after logging out, people go back to the panel.
$cfg['Servers'][$i]['SignonURL'] = '/';
$cfg['Servers'][$i]['LogoutURL'] = '/';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['socket'] = '/run/mysqld/mysqld.sock';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = false;
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|performance_schema|mysql|sys)$';

$cfg['TempDir'] = '/var/lib/phpmyadmin/tmp';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['VersionCheck'] = false;
$cfg['SendErrorReports'] = 'never';
$cfg['ShowServerInfo'] = false;
$cfg['ShowPhpInfo'] = false;
$cfg['ShowCreateDb'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['AllowUserDropDatabase'] = false;
$cfg['AllowArbitraryServer'] = false;
$cfg['LoginCookieValidity'] = 14400;
$cfg['ExecTimeLimit'] = 600;
$cfg['PmaNoRelation_DisableWarning'] = true;
$cfg['SuhosinDisableWarning'] = true;
$cfg['DefaultLang'] = 'en';
