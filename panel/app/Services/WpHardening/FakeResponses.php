<?php

namespace App\Services\WpHardening;

use App\Services\Agent\FakeAgentClient;

/** wphard.* answers for the fake agent (local development and tests): remembers what was switched on. */
final class FakeResponses
{
    /** @var array<string, array{on: array<string, bool>, salts_at: ?int, undo: bool}> */
    private static array $sites = [];

    public static function register(FakeAgentClient $agent): void
    {
        self::$sites = [];
        $agent->respond('wphard.status', fn (array $p) => self::answer($p));
        $agent->respond('wphard.apply', function (array $p) {
            $s = &self::site($p);
            $changed = [];
            foreach ((array) ($p['measures'] ?? []) as $key => $on) {
                if (in_array($key, Hardening::MEASURES, true) && ($s['on'][$key] ?? false) !== (bool) $on) {
                    $s['on'][$key] = (bool) $on;
                    $changed[] = $key;
                }
            }

            return [...self::answer($p), 'changed' => $changed];
        });
        $agent->respond('wphard.salts', function (array $p) {
            $s = &self::site($p);
            if ($p['undo'] ?? false) {
                $s['salts_at'] = null;
                $s['undo'] = false;
            } else {
                $s['salts_at'] = time();
                $s['undo'] = true;
            }

            return [...self::answer($p), 'changed' => ['salts']];
        });
    }

    private static function &site(array $p): array
    {
        $key = ($p['domain'] ?? '').'/'.($p['path'] ?? '');
        self::$sites[$key] ??= ['on' => [], 'salts_at' => null, 'undo' => false];

        return self::$sites[$key];
    }

    private static function answer(array $p): array
    {
        $s = self::site($p);
        $on = fn (string $k) => (bool) ($s['on'][$k] ?? false);
        $root = ($p['path'] ?? '') === '';

        return [
            'domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '',
            'measures' => [
                'file_edit' => ['on' => $on('file_edit'), 'via' => null],
                'php_folders' => ['on' => $on('php_folders'), 'uploads_by_server' => $root],
                'wp_config' => ['on' => $on('wp_config'), 'mode' => $on('wp_config') ? '0600' : '0644', 'private' => $on('wp_config'),
                    'web_blocked' => $on('wp_config'), 'location' => 'install'],
                'hide_version' => ['on' => $on('hide_version')],
                'user_enum' => ['on' => $on('user_enum')],
            ],
            'on' => count(array_filter(Hardening::MEASURES, $on)),
            'total' => count(Hardening::MEASURES),
            'salts' => ['complete' => true, 'default' => false, 'refreshed_at' => $s['salts_at'], 'undo' => $s['undo']],
            'server' => ['directory_listing' => true, 'php_in_uploads' => true, 'xmlrpc' => true],
            'problems' => [],
        ];
    }
}
