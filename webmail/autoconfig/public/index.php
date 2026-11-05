<?php

/*
 * Mail client discovery and MTA-STS, served for every hosted domain:
 *
 *   autoconfig.<domain>   /mail/config-v1.1.xml, /.well-known/autoconfig/mail/config-v1.1.xml
 *   autodiscover.<domain> /autodiscover/autodiscover.xml (POST, Outlook)
 *   mta-sts.<domain>      /.well-known/mta-sts.txt
 *
 * Only domains listed in domains.json (written by the agent action
 * mail.set_routing) get an answer; everything else is a 404.
 */

declare(strict_types=1);

use Rhis\DnsMail\ClientSetup\Autoconfig;
use Rhis\DnsMail\ClientSetup\Autodiscover;
use Rhis\DnsMail\ClientSetup\MailClientSettings;
use Rhis\DnsMail\Mail\MtaSts;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Rhis\\DnsMail\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../../../packages/dns-mail/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

const STATE_FILE = '/var/lib/webhosting-panel/mail/domains.json';

function respond(int $status, string $type, string $body, int $maxAge = 3600): never
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=' . $maxAge);
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

function notFound(): never
{
    respond(404, 'text/plain; charset=utf-8', "Not found\n", 300);
}

$state = is_readable(STATE_FILE) ? json_decode((string) file_get_contents(STATE_FILE), true) : null;
if (!is_array($state) || !isset($state['mail_host'], $state['domains']) || !is_array($state['domains'])) {
    respond(503, 'text/plain; charset=utf-8', "Not configured\n", 60);
}

$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
if (!preg_match('/^(autoconfig|autodiscover|mta-sts)\.([a-z0-9.-]+)$/', $host, $m)) {
    notFound();
}
[, $service, $domain] = $m;
$info = $state['domains'][$domain] ?? null;
if (!is_array($info)) {
    notFound();
}
$path = strtolower(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$brand = (string) ($state['brand'] ?? 'RHIS');
$mailHost = (string) $state['mail_host'];

if ($service === 'mta-sts') {
    $sts = $info['mta_sts'] ?? null;
    if ($path !== '/.well-known/mta-sts.txt' || !is_array($sts)) {
        notFound();
    }
    $policy = new MtaSts(array_values($sts['mx'] ?? []), (string) ($sts['mode'] ?? 'testing'), (int) ($sts['max_age'] ?? 604800));
    respond(200, 'text/plain; charset=utf-8', $policy->policy(), 86400);
}

// Discovery only for mailboxes on this server.
if (($info['provider'] ?? '') !== 'rhis') {
    notFound();
}

if ($service === 'autoconfig' && in_array($path, ['/mail/config-v1.1.xml', '/.well-known/autoconfig/mail/config-v1.1.xml'], true)) {
    $email = (string) ($_GET['emailaddress'] ?? ('user@' . $domain));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($email), '@' . $domain)) {
        $email = 'user@' . $domain;
    }
    respond(200, 'application/xml; charset=utf-8', Autoconfig::xml(new MailClientSettings($email, $mailHost, brandName: $brand)));
}

if ($service === 'autodiscover' && $path === '/autodiscover/autodiscover.xml') {
    $email = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        ? Autodiscover::emailFromRequest((string) file_get_contents('php://input', length: 10_000))
        : null;
    if ($email === null || !str_ends_with(strtolower($email), '@' . $domain)) {
        notFound();
    }
    respond(200, 'application/xml; charset=utf-8', Autodiscover::xml(new MailClientSettings($email, $mailHost, brandName: $brand)), 0);
}

notFound();
