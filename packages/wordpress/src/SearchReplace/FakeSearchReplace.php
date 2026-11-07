<?php

declare(strict_types=1);

namespace Rhis\WordPress\SearchReplace;

use App\Models\Website;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Realistic wp.sr_* answers for local development and tests (WHP_AGENT_DRIVER=fake). */
final class FakeSearchReplace
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp.sr_addresses', fn (array $p) => self::addresses($p));
        $agent->respond('wp.sr_preview', fn (array $p) => self::job(self::result($p, true)));
        $agent->respond('wp.sr_apply', fn (array $p) => self::job(self::result($p, false)));

        // job.status answers for our own jobs; everything else goes to whoever answered before us.
        $previous = (fn () => $this->responses['job.status'] ?? null)->call($agent);
        $agent->respond('job.status', function (array $p) use ($previous) {
            $state = Cache::get('fake-wsr:'.($p['job'] ?? ''));
            if ($state) {
                return $state;
            }
            if ($previous instanceof \Closure) {
                return $previous($p);
            }

            return is_array($previous) ? $previous
                : ['id' => $p['job'] ?? '', 'status' => 'succeeded', 'log' => ['Done'], 'result' => [], 'error' => null];
        });
    }

    private static function job(array $result): array
    {
        $id = (string) Str::uuid();
        Cache::put('fake-wsr:'.$id, ['id' => $id, 'status' => 'succeeded', 'log' => ['Dry run: nothing is changed', 'Done'],
            'result' => $result, 'error' => null], now()->addDay());

        return ['job' => $id];
    }

    private static function addresses(array $p): array
    {
        $domain = (string) ($p['domain'] ?? 'example.test');
        $site = Website::where('domain', $domain)->first();
        $ssl = ($site?->ssl_status ?? 'active') === 'active';
        $hosts = [['host' => $domain, 'ssl' => $ssl], ['host' => 'www.'.$domain, 'ssl' => $ssl]];
        foreach ((array) ($site?->aliases ?? []) as $alias) {
            if (is_string($alias) && ! in_array($alias, array_column($hosts, 'host'), true)) {
                $hosts[] = ['host' => $alias, 'ssl' => $ssl];
            }
        }
        if (class_exists(\Rhis\DomainAliases\Models\DomainAlias::class) && $site) {
            foreach (\Rhis\DomainAliases\Models\DomainAlias::where('website_id', $site->id)->where('mode', 'serve')->get() as $a) {
                foreach ($a->names() as $name) {
                    $hosts[] = ['host' => $name, 'ssl' => $a->ssl_status === 'active'];
                }
            }
        }
        $path = (string) ($p['path'] ?? '');
        $home = 'https://'.$domain.($path !== '' ? '/'.$path : '');

        return ['domain' => $domain, 'path' => $path, 'home' => $home, 'siteurl' => $home,
            'constants' => ['WP_HOME' => null, 'WP_SITEURL' => null], 'multisite' => false, 'hosts' => $hosts];
    }

    private static function result(array $p, bool $dry): array
    {
        $domain = (string) ($p['domain'] ?? 'example.test');
        $domainMode = ($p['mode'] ?? 'text') === 'domain';
        $old = $domainMode ? 'https://'.$domain : (string) ($p['search'] ?? '');
        $new = $domainMode ? ($p['scheme'] ?? 'https').'://'.($p['host'] ?? $domain) : (string) ($p['replace'] ?? '');
        $tables = [
            ['table' => 'wp_posts', 'column' => 'post_content', 'count' => 37, 'type' => 'SQL'],
            ['table' => 'wp_postmeta', 'column' => 'meta_value', 'count' => 12, 'type' => 'PHP'],
            ['table' => 'wp_options', 'column' => 'option_value', 'count' => 4, 'type' => 'PHP'],
        ];
        $out = [
            'domain' => $domain, 'path' => (string) ($p['path'] ?? ''), 'mode' => $domainMode ? 'domain' : 'text',
            'pairs' => [['search' => $domainMode ? 'http(s)://(www.)'.$domain : $old, 'replace' => $new]],
            'old_home' => $domainMode ? $old : null, 'new_home' => $domainMode ? $new : null,
            'total' => 53, 'tables' => $tables,
        ];
        if ($dry) {
            $out['samples'] = [
                ['table' => 'wp_options', 'column' => 'option_value', 'row' => '1', 'old' => $old, 'new' => $new],
                ['table' => 'wp_posts', 'column' => 'post_content', 'row' => '42',
                    'old' => '…<a href="'.$old.'/contact">Neem contact op</a>…', 'new' => '…<a href="'.$new.'/contact">Neem contact op</a>…'],
                ['table' => 'wp_postmeta', 'column' => 'meta_value', 'row' => '311',
                    'old' => 'a:2:{s:3:"url";s:'.(strlen($old) + 9).':"'.$old.'/logo.png";…', 'new' => 'a:2:{s:3:"url";s:'.(strlen($new) + 9).':"'.$new.'/logo.png";…'],
            ];
            $out['samples_truncated'] = true;
        } else {
            $out += ['constants' => [], 'snapshot' => now()->format('Ymd-His'), 'health' => ['before' => 200, 'after' => 200], 'rolled_back' => false];
        }

        return $out;
    }
}
