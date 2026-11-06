<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Testing;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Str;

/** Answers of the fake agent (local development and tests) for the server management actions. */
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
        $agent->respond('server.status', fn () => [
            'os' => 'Debian GNU/Linux 13 (trixie)', 'kernel' => '6.12.41+deb13-amd64', 'kernel_newest' => '6.12.48+deb13-amd64',
            'kernels_installed' => ['6.12.41+deb13-amd64', '6.12.48+deb13-amd64'],
            'boot_time' => now()->subDays(12)->timestamp, 'uptime_seconds' => 12 * 86400,
            'reboot_reasons' => [['kind' => 'kernel', 'detail' => '6.12.41+deb13-amd64 -> 6.12.48+deb13-amd64']],
            'reboot' => null, 'apt_lists_age' => 3600, 'apt_running' => [], 'auto_updates' => 'window', 'can_shutdown' => false,
        ]);
        $agent->respond('server.updates', fn () => [
            'packages' => [
                ['name' => 'libssl3t64', 'current' => '3.5.1-1', 'candidate' => '3.5.1-1+deb13u1', 'arch' => 'amd64', 'origin' => 'Debian-Security:13/stable-security', 'security' => true, 'new' => false, 'kernel' => false],
                ['name' => 'openssl', 'current' => '3.5.1-1', 'candidate' => '3.5.1-1+deb13u1', 'arch' => 'amd64', 'origin' => 'Debian-Security:13/stable-security', 'security' => true, 'new' => false, 'kernel' => false],
                ['name' => 'nginx', 'current' => '1.26.3-3', 'candidate' => '1.26.3-3+deb13u1', 'arch' => 'amd64', 'origin' => 'Debian:13.2/stable', 'security' => false, 'new' => false, 'kernel' => false],
            ],
            'total' => 3, 'security' => 2, 'kernel' => false, 'apt_lists_age' => 60,
        ]);
        foreach (['server.security_scan', 'server.upgrade', 'server.db_optimize', 'server.cleanup', 'server.php_install', 'server.php_remove'] as $action) {
            $agent->respond($action, fn () => ['job' => (string) Str::uuid()]);
        }
        $agent->respond('server.apt_status', fn (array $p) => ['id' => $p['run'] ?? '', 'status' => 'succeeded', 'exit_code' => 0, 'log' => ['done']]);
        $agent->respond('server.auto_updates', fn (array $p) => ['mode' => $p['mode'] ?? 'daily']);
        $agent->respond('server.reboot', fn (array $p) => ['scheduled' => true, 'at' => time() + ($p['delay'] ?? 10), 'reason' => $p['reason'] ?? 'manual']);
        $agent->respond('server.reboot_cancel', ['cancelled' => true]);
        $agent->respond('server.health', fn (array $p) => [
            'services' => [['service' => 'nginx', 'label' => 'Nginx', 'installed' => true, 'active' => true, 'state' => 'running', 'since' => null]],
            'services_down' => [],
            'sites' => array_map(fn ($s) => ['domain' => $s['domain'], 'code' => 200, 'ms' => 42, 'ok' => true, 'error' => null], $p['sites'] ?? []),
            'sites_down' => [], 'boot_time' => now()->subDays(12)->timestamp, 'checked_at' => time(),
        ]);
        $agent->respond('server.php_versions', fn () => [
            'supported' => ['8.3', '8.4', '8.5'], 'panel' => '8.4', 'installed' => ['8.3', '8.4', '8.5'], 'configured' => ['8.3', '8.4', '8.5'],
            'sites' => ['8.3' => 0, '8.4' => 0, '8.5' => 0],
        ]);
    }
}
