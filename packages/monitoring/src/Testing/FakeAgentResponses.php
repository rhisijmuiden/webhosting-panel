<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Testing;

use App\Services\Agent\FakeAgentClient;

/** Answers of the fake agent (local development and tests) for monitor.check. */
final class FakeAgentResponses
{
    private static ?\WeakMap $done = null;

    public static function register(FakeAgentClient $agent): void
    {
        // afterResolving runs on every resolve: register once, so a test can override an answer.
        self::$done ??= new \WeakMap;
        if (isset(self::$done[$agent])) {
            return;
        }
        self::$done[$agent] = true;
        $agent->respond('monitor.check', fn (array $p) => [
            'sites' => array_map(fn ($s) => [
                'domain' => $s['domain'], 'ok' => true, 'code' => 200, 'ms' => 60 + (crc32($s['domain']) % 240), 'error' => null,
                'ssl' => ($s['ssl'] ?? false) ? ['ok' => true, 'days_left' => 40 + (crc32($s['domain']) % 40), 'expires_at' => time() + 60 * 86400,
                    'issuer' => "Let's Encrypt", 'matches' => true, 'error' => null] : null,
            ], $p['sites'] ?? []),
            'services' => array_map(fn ($u, $l) => ['service' => $u, 'label' => $l, 'installed' => true, 'active' => true, 'state' => 'running', 'since' => null],
                ['nginx', 'php8.4-fpm', 'mariadb', 'valkey-server', 'postfix', 'dovecot', 'rspamd', 'whp-queue'],
                ['Nginx', 'PHP-FPM 8.4', 'MariaDB', 'Valkey', 'Postfix', 'Dovecot', 'Rspamd', 'Panel queue worker']),
            'disks' => [
                ['mount' => '/', 'device' => '/dev/vda1', 'fs' => 'ext4', 'total' => 160 * 1024 ** 3, 'used' => 61 * 1024 ** 3, 'free' => 99 * 1024 ** 3, 'pct' => 38.1, 'inodes_pct' => 9.4],
                ['mount' => '/var/www', 'device' => '/dev/vdb1', 'fs' => 'xfs', 'total' => 500 * 1024 ** 3, 'used' => 212 * 1024 ** 3, 'free' => 288 * 1024 ** 3, 'pct' => 42.4, 'inodes_pct' => 3.1],
            ],
            'memory' => ['total' => 16 * 1024 ** 3, 'available' => 9 * 1024 ** 3, 'pct' => 43.7, 'swap_total' => 2 * 1024 ** 3, 'swap_pct' => 0.0],
            'load' => [0.42, 0.38, 0.31], 'cpus' => 8, 'mail_queue' => 3, 'checked_at' => time(),
        ]);
    }
}
