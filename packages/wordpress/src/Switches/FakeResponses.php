<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** Answers of the wp.* switch actions for the fake agent (WHP_AGENT_DRIVER=fake: local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $key = fn (array $p) => 'fake-agent.wp-switches.'.md5(($p['domain'] ?? '').'|'.($p['path'] ?? ''));
        $state = function (array $p) use ($key): array {
            $s = Cache::get($key($p), ['maintenance' => false, 'debug' => false, 'indexing' => true, 'title' => '', 'message' => '']);

            return [
                'domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '',
                'maintenance' => $s['maintenance'], 'maintenance_since' => $s['maintenance'] ? now()->subMinutes(12)->timestamp : null,
                'maintenance_title' => $s['title'], 'maintenance_message' => $s['message'],
                'debug' => $s['debug'], 'debug_log' => $s['debug'] ? 'panel' : null, 'debug_display' => false,
                'indexing' => $s['indexing'],
                'log' => ['file' => 'wp-debug.log', 'size' => $s['debug'] ? 18_432 : 0, 'modified' => now()->timestamp, 'exists' => $s['debug']],
                'public_log' => null, 'checked_at' => now()->timestamp,
            ];
        };
        $set = fn (string $field) => function (array $p) use ($key, $state, $field): array {
            $s = Cache::get($key($p), ['maintenance' => false, 'debug' => false, 'indexing' => true, 'title' => '', 'message' => '']);
            $s[$field] = (bool) ($p['enabled'] ?? false);
            if ($field === 'maintenance' && $s[$field]) {
                $s['title'] = $p['title'] ?? '';
                $s['message'] = $p['message'] ?? '';
            }
            Cache::forever($key($p), $s);

            return $state($p);
        };

        $agent->respond('wp.switches', $state);
        $agent->respond('wp.maintenance_set', $set('maintenance'));
        $agent->respond('wp.debug_set', $set('debug'));
        $agent->respond('wp.indexing_set', $set('indexing'));
        $agent->respond('wp.cache_clear', fn (array $p) => ['domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '', 'ok' => true,
            'cleared_at' => now()->timestamp, 'steps' => [
                ['step' => 'page_cache', 'ok' => true], ['step' => 'object_cache', 'ok' => true, 'error' => null],
                ['step' => 'plugins', 'ok' => true, 'plugins' => ['WP Rocket', 'Elementor CSS'], 'error' => null],
                ['step' => 'transients', 'ok' => true],
            ]]);
        $agent->respond('wp.debug_log', function (array $p): array {
            $entries = collect([
                ['time' => now()->subMinutes(2)->format('d-M-Y H:i:s'), 'tz' => 'UTC', 'level' => 'error', 'source' => 'web',
                    'message' => "PHP Fatal error:  Uncaught Error: Call to undefined function shop_price() in /var/www/vhosts/example/public/wp-content/themes/shop/functions.php:118\nStack trace:\n#0 /var/www/vhosts/example/public/wp-includes/class-wp-hook.php(324): {closure}()\n#1 {main}\n  thrown in /var/www/vhosts/example/public/wp-content/themes/shop/functions.php on line 118"],
                ['time' => now()->subMinutes(9)->format('d-M-Y H:i:s'), 'tz' => 'UTC', 'level' => 'warning', 'source' => 'web',
                    'message' => 'PHP Warning:  Undefined array key "price" in /var/www/vhosts/example/public/wp-content/plugins/shop-tools/cart.php on line 42'],
                ['time' => now()->subMinutes(15)->format('d-M-Y H:i:s'), 'tz' => 'UTC', 'level' => 'deprecated', 'source' => 'cron',
                    'message' => 'PHP Deprecated:  Creation of dynamic property Shop_Cart::$total is deprecated in /var/www/vhosts/example/public/wp-content/plugins/shop-tools/cart.php on line 12'],
                ['time' => now()->subMinutes(31)->format('d-M-Y H:i:s'), 'tz' => 'UTC', 'level' => 'notice', 'source' => 'web',
                    'message' => 'PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly. Translation loading for the shop-tools domain was triggered too early.'],
            ]);
            $counts = ['error' => 1, 'warning' => 1, 'notice' => 1, 'deprecated' => 1, 'other' => 0];
            $level = $p['level'] ?? 'all';
            $q = mb_strtolower((string) ($p['q'] ?? ''));
            $picked = $entries->filter(fn ($e) => ($level === 'all' || $e['level'] === $level) && ($q === '' || str_contains(mb_strtolower($e['message']), $q)))->values();

            return ['domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '', 'file' => 'wp-debug.log', 'exists' => true,
                'size' => 18_432, 'modified' => now()->subMinutes(2)->timestamp, 'truncated' => false,
                'counts' => $counts, 'matched' => $picked->count(), 'entries' => $picked->take((int) ($p['limit'] ?? 200))->all()];
        });
        $agent->respond('wp.debug_log_clear', fn (array $p) => ['domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '',
            'which' => $p['which'] ?? 'panel', 'cleared' => true]);
    }
}
