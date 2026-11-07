<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions;

use App\Services\Agent\AgentException;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** Answers of the fake agent (local development and tests) for wp.ext_*: a small WordPress that remembers changes. */
final class FakeExtensions
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp.ext_list', fn (array $p) => self::state($agent, $p));
        $agent->respond('wp.ext_apply', fn (array $p) => self::apply($agent, $p));
        $agent->respond('wp.ext_search', fn (array $p) => self::search($p));
        $agent->respond('wp.ext_install', fn (array $p) => self::install($agent, $p));
    }

    private static function key(array $p): string
    {
        return 'fake-agent.wpext.'.($p['domain'] ?? '').'.'.($p['path'] ?? '');
    }

    private static function state(FakeAgentClient $agent, array $p): array
    {
        return Cache::get(self::key($p)) ?? (function () use ($agent, $p) {
            $s = $agent->call('wp.status', ['domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '']);

            return ['domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '', 'plugins' => $s['plugins'], 'themes' => $s['themes']];
        })();
    }

    private static function save(array $p, array $state): void
    {
        Cache::put(self::key($p), $state, 3600);
    }

    private static function apply(FakeAgentClient $agent, array $p): array
    {
        $state = self::state($agent, $p);
        $list = $p['kind'].'s';
        $done = $errors = [];
        foreach ($p['slugs'] as $slug) {
            $i = collect($state[$list])->search(fn ($x) => $x['name'] === $slug);
            if ($i === false) {
                $errors[] = ['slug' => $slug, 'error' => "{$p['kind']} {$slug} is not installed"];

                continue;
            }
            $item = &$state[$list][$i];
            switch ($p['op']) {
                case 'activate':
                    if ($p['kind'] === 'theme') {
                        foreach ($state['themes'] as &$t) {
                            $t['status'] = $t['status'] === 'active' ? 'inactive' : $t['status'];
                        }
                        unset($t);
                    }
                    $item['status'] = 'active';
                    break;
                case 'deactivate':
                    $item['status'] = 'inactive';
                    break;
                case 'delete':
                    if (in_array($item['status'], ['active', 'parent'], true)) {
                        $errors[] = ['slug' => $slug, 'error' => "{$slug} is active: deactivate it first"];
                        unset($item);

                        continue 2;
                    }
                    unset($item);
                    array_splice($state[$list], $i, 1);
                    $done[] = $slug;

                    continue 2;
                default:
                    $item['auto_update'] = $p['op'] === 'auto_update_on';
            }
            unset($item);
            $done[] = $slug;
        }
        self::save($p, $state);

        return $state + ['kind' => $p['kind'], 'op' => $p['op'], 'done' => $done, 'errors' => $errors, 'rolled_back' => false,
            'health' => $p['op'] === 'activate' ? ['before' => 200, 'after' => 200] : null];
    }

    private static function catalog(): array
    {
        $row = fn ($slug, $name, $author, $rating, $installs, $desc, $tested = '6.7') => [
            'slug' => $slug, 'name' => $name, 'version' => '1.0', 'author' => $author, 'rating' => $rating, 'num_ratings' => (int) ($installs / 400),
            'active_installs' => $installs, 'description' => $desc, 'requires' => '6.2', 'tested' => $tested, 'requires_php' => '7.4', 'image' => null,
        ];

        return [
            'plugin' => [
                $row('wordpress-seo', 'Yoast SEO', 'Team Yoast', 96, 10000000, 'Improve your WordPress SEO: write better content and have a fully optimized WordPress site.'),
                $row('seo-by-rank-math', 'Rank Math SEO', 'Rank Math SEO', 98, 3000000, 'Rank Math SEO is the best WordPress SEO plugin with the features of many SEO and AI SEO tools in a single package.'),
                $row('all-in-one-seo-pack', 'All in One SEO', 'All in One SEO Team', 92, 3000000, 'AIOSEO is the most powerful WordPress SEO plugin.'),
                $row('contact-form-7', 'Contact Form 7', 'Takayuki Miyoshi', 80, 10000000, 'Just another contact form plugin. Simple but flexible.'),
                $row('wpforms-lite', 'WPForms', 'WPForms', 98, 6000000, 'The best drag and drop form builder for WordPress.'),
                $row('the-seo-framework', 'The SEO Framework', 'Sybre Waaijer', 98, 200000, 'An automated, advanced, accessible, unbranded and extremely fast SEO solution.', '6.6'),
            ],
            'theme' => [
                $row('astra', 'Astra', 'Brainstorm Force', 98, 1000000, 'Astra is fast, fully customizable and beautiful.'),
                $row('kadence', 'Kadence', 'Kadence WP', 98, 300000, 'Kadence is a lightweight yet full featured WordPress theme.'),
                $row('twentytwentyfive', 'Twenty Twenty-Five', 'the WordPress team', 90, 1000000, 'Twenty Twenty-Five emphasizes simplicity and adaptability.'),
            ],
        ];
    }

    private static function search(array $p): array
    {
        $q = mb_strtolower($p['query'] ?? '');
        $words = array_filter(explode(' ', $q));
        $results = array_values(array_filter(self::catalog()[$p['kind']] ?? [], function ($r) use ($words) {
            $text = mb_strtolower($r['name'].' '.$r['slug'].' '.$r['description']);

            return $words && collect($words)->every(fn ($w) => str_contains($text, $w));
        }));

        return ['kind' => $p['kind'], 'query' => $p['query'], 'page' => $p['page'] ?? 1, 'total' => count($results), 'pages' => 1, 'results' => $results];
    }

    private static function install(FakeAgentClient $agent, array $p): array
    {
        $state = self::state($agent, $p);
        $list = $p['kind'].'s';
        if (collect($state[$list])->contains('name', $p['slug'])) {
            throw new AgentException('conflict', "{$p['kind']} {$p['slug']} is already installed");
        }
        $found = collect(self::catalog()[$p['kind']])->firstWhere('slug', $p['slug']);
        $state[$list][] = ['name' => $p['slug'], 'title' => $found['name'] ?? $p['slug'], 'status' => 'inactive', 'version' => $found['version'] ?? '1.0',
            'update' => false, 'update_version' => null, 'auto_update' => false];
        self::save($p, $state);
        $out = $state + ['kind' => $p['kind'], 'op' => 'install', 'slug' => $p['slug'], 'done' => [$p['slug']], 'errors' => [], 'rolled_back' => false, 'health' => null];
        if (! empty($p['activate'])) {
            $act = self::apply($agent, ['kind' => $p['kind'], 'op' => 'activate', 'slugs' => [$p['slug']]] + $p);
            $out = array_merge($out, $act, ['done' => [$p['slug']], 'activated' => true, 'op' => 'install']);
        }

        return $out;
    }
}
