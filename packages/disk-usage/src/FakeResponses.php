<?php

declare(strict_types=1);

namespace Rhis\DiskUsage;

use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** disk.* answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests). */
final class FakeResponses
{
    private const MB = 1024 * 1024;

    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('disk.scan', function (array $p) {
            Cache::put('fake-disk.scanned.'.($p['domain'] ?? ''), time() - 120, 3600);

            return ['job' => 'fake-disk-scan', 'already_running' => false];
        });
        $agent->respond('disk.scan_all', fn () => ['job' => 'fake-disk-scan-all']);
        $agent->respond('disk.usage', fn (array $p) => self::usage((string) ($p['domain'] ?? ''), (string) ($p['path'] ?? '')));
        $agent->respond('disk.summary', function () {
            $sites = [];
            foreach (Website::withoutGlobalScopes()->get(['domain', 'disk_used_mb']) as $w) {
                if ($at = Cache::get('fake-disk.scanned.'.$w->domain)) {
                    $sites[$w->domain] = ['bytes' => self::tree($w->domain)['s'], 'files' => self::tree($w->domain)['f'], 'scanned_at' => $at];
                }
            }
            $dbs = SiteDatabase::pluck('name')->mapWithKeys(fn ($n) => [$n => (crc32($n) % 400 + 12) * self::MB])->all();

            return ['sites' => $sites, 'databases' => $dbs, 'scanning' => []];
        });
    }

    private static function usage(string $domain, string $path): array
    {
        $at = Cache::get('fake-disk.scanned.'.$domain);
        if ($at === null) {
            return ['domain' => $domain, 'scanned_at' => null, 'scanning' => false, 'node' => null];
        }
        $tree = self::tree($domain);
        $node = $tree;
        $found = [];
        foreach (array_filter(explode('/', $path)) as $part) {
            $next = collect($node['c'])->firstWhere('n', $part);
            if ($next === null) {
                break;
            }
            $node = $next;
            $found[] = $part;
        }
        $result = [
            'domain' => $domain, 'scanned_at' => $at, 'seconds' => 3.4, 'scanning' => false, 'partial' => false, 'errors' => 0,
            'total_bytes' => $tree['s'], 'total_files' => $tree['f'], 'found' => count($found) === count(array_filter(explode('/', $path))),
            'node' => [
                'path' => implode('/', $found), 'name' => $node['n'], 'bytes' => $node['s'], 'files' => $node['f'], 'folders' => count($node['c']),
                'direct_bytes' => $node['fs'], 'direct_files' => count($node['top']), 'truncated' => false, 'other' => ['bytes' => 0, 'count' => 0],
                'children' => array_map(fn ($c) => ['name' => $c['n'], 'bytes' => $c['s'], 'files' => $c['f'], 'folders' => count($c['c']),
                    'truncated' => false, 'has_children' => $c['c'] !== []], $node['c']),
                'top_files' => array_map(fn ($t) => ['name' => $t[0], 'bytes' => $t[1], 'mtime' => $t[2]], $node['top']),
            ],
        ];
        if ($found === []) {
            $result['largest'] = [
                ['path' => 'public/wp-content/updraft/backup_2026-10-01-db.zip', 'bytes' => 412 * self::MB, 'mtime' => strtotime('2026-10-01')],
                ['path' => 'public/wp-content/ai1wm-backups/klant-20260612.wpress', 'bytes' => 388 * self::MB, 'mtime' => strtotime('2026-06-12')],
                ['path' => 'public/wp-content/uploads/2026/03/bedrijfsfilm.mp4', 'bytes' => 164 * self::MB, 'mtime' => strtotime('2026-03-14')],
                ['path' => 'logs/access.log', 'bytes' => 58 * self::MB, 'mtime' => time() - 60],
                ['path' => 'public/wp-content/uploads/2025/11/brochure-print.pdf', 'bytes' => 41 * self::MB, 'mtime' => strtotime('2025-11-03')],
                ['path' => 'public/oude-site.tar.gz', 'bytes' => 36 * self::MB, 'mtime' => strtotime('2024-02-20')],
            ];
        }

        return $result;
    }

    /** A WordPress site with some typical space eaters. */
    private static function tree(string $domain): array
    {
        $mb = fn (float $n) => (int) ($n * self::MB);
        $spec = ['public' => [
            'wp-content' => [
                'uploads' => ['2026' => ['01' => 120, '02' => 96, '03' => 288], '2025' => ['11' => 230, '06' => 140], '2024' => 310],
                'updraft' => ['#' => [['backup_2026-10-01-db.zip', 412], ['backup_2026-09-01-db.zip', 402]]],
                'ai1wm-backups' => ['#' => [['klant-20260612.wpress', 388]]],
                'plugins' => ['woocommerce' => 96, 'elementor' => 84, 'wordfence' => 31, 'yoast-seo' => 22],
                'cache' => ['min' => 18, 'page' => 64],
                'themes' => ['astra' => 14, 'twentytwentyfive' => 6],
            ],
            'wp-includes' => 54, 'wp-admin' => 12,
            '#' => [['oude-site.tar.gz', 36], ['wp-config.php', 0.01], ['index.php', 0.001]],
        ], 'logs' => ['#' => [['access.log', 58], ['error.log', 4], ['access.log.1.gz', 12]]], 'tmp' => 2, 'sessions' => 0.5];
        $build = function (string $name, array|int|float $spec) use (&$build, $mb): array {
            if (! is_array($spec)) {
                return ['n' => $name, 's' => $mb($spec), 'f' => max(1, (int) ($spec * 9)), 'fs' => 0, 'c' => [], 'top' => []];
            }
            $files = $spec['#'] ?? [];
            unset($spec['#']);
            $children = [];
            foreach ($spec as $n => $s) {
                $children[] = $build((string) $n, $s);
            }
            usort($children, fn ($a, $b) => $b['s'] <=> $a['s']);
            $top = array_map(fn ($f) => [$f[0], $mb($f[1]), strtotime('2026-10-01')], $files);
            $fs = array_sum(array_column($top, 1));

            return ['n' => $name, 's' => $fs + array_sum(array_column($children, 's')), 'f' => count($files) + array_sum(array_column($children, 'f')),
                'fs' => $fs, 'c' => $children, 'top' => $top];
        };

        return $build('', $spec);
    }
}
