<?php

declare(strict_types=1);

namespace Rhis\ObjectCache;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** Answers for objcache.* when the panel runs without a real agent (WHP_AGENT_DRIVER=fake). */
final class FakeObjectCache
{
    public static function register(FakeAgentClient $agent): void
    {
        $status = function (string $domain) {
            $st = Cache::get("fake-objcache.$domain");
            if (! $st || ! $st['enabled']) {
                return ['domain' => $domain, 'enabled' => false, 'state' => 'off'];
            }
            $seed = crc32($domain);
            $max = $st['memory_mb'] * 1024 * 1024;
            $hits = 40000 + $seed % 90000;
            $misses = 2000 + $seed % 9000;

            return [
                'domain' => $domain, 'enabled' => true, 'memory_mb' => $st['memory_mb'], 'state' => 'running', 'autostart' => true,
                'socket' => '/run/whp-objcache/s_'.str_replace(['.', '-'], '_', $domain).'/valkey.sock',
                'unit' => 'whp-objcache-s_'.str_replace(['.', '-'], '_', $domain).'.service',
                'memory_bytes' => (int) ($max * 0.4), 'suspended' => false, 'wordpress' => $st['wordpress'],
                'stats' => [
                    'version' => '8.1.1', 'uptime_seconds' => 86400 * 3 + $seed % 80000,
                    'used_memory_bytes' => (int) ($max * (0.18 + ($seed % 50) / 100)), 'maxmemory_bytes' => $max,
                    'keys' => $st['flushed'] ? 0 : 1500 + $seed % 9000, 'hits' => $hits, 'misses' => $misses,
                    'hit_ratio' => round($hits / ($hits + $misses) * 100, 1), 'evicted_keys' => $seed % 40, 'clients' => 2,
                ],
            ];
        };
        $agent->respond('objcache.apply', function (array $p) use ($status) {
            $domain = $p['domain'] ?? '';
            $installs = [];
            if (($p['enabled'] ?? false) && ($p['wordpress'] ?? true) && class_exists(\Rhis\WordPress\Models\WordPressInstall::class)) {
                $installs = \Rhis\WordPress\Models\WordPressInstall::whereHas('website', fn ($q) => $q->where('domain', $domain))->get()
                    ->map(fn ($i) => ['path' => $i->path, 'configured' => true,
                        'woocommerce' => collect($i->plugins ?? [])->contains(fn ($pl) => ($pl['name'] ?? null) === 'woocommerce')])->all();
            }
            Cache::forever("fake-objcache.$domain", ['enabled' => (bool) ($p['enabled'] ?? false), 'memory_mb' => (int) ($p['memory_mb'] ?? 64),
                'wordpress' => $installs, 'flushed' => false]);

            return $status($domain);
        });
        $agent->respond('objcache.status', fn (array $p) => $status($p['domain'] ?? ''));
        $agent->respond('objcache.list', fn (array $p) => ['sites' => collect($p['domains'] ?? [])
            ->mapWithKeys(fn ($d) => [$d => $status($d)])->filter(fn ($s) => $s['enabled'])->all()]);
        $agent->respond('objcache.flush', function (array $p) use ($status) {
            $domain = $p['domain'] ?? '';
            $keys = $status($domain)['stats']['keys'] ?? 0;
            $st = Cache::get("fake-objcache.$domain");
            if ($st) {
                Cache::forever("fake-objcache.$domain", ['flushed' => true] + $st);
            }

            return ['domain' => $domain, 'flushed' => true, 'keys_removed' => $keys, 'flushed_at' => time()];
        });
        $agent->respond('objcache.suspend', fn (array $p) => ['domain' => $p['domain'] ?? '', 'suspended' => (bool) ($p['suspended'] ?? false), 'changed' => true]);
        $agent->respond('objcache.remove', function (array $p) {
            Cache::forget('fake-objcache.'.($p['domain'] ?? ''));

            return ['domain' => $p['domain'] ?? '', 'removed' => true];
        });
    }
}
