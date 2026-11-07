<?php

namespace App\Services\WpImport;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/**
 * wp_import.* answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests).
 * Jobs of the fake import finish at once; job.status answers them and keeps the
 * fake agent's default answer for every other job.
 */
final class FakeWpImport
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp_import.inspect', function (array $p) {
            $job = 'fake-wpi-inspect-'.md5(($p['import'] ?? '').microtime());
            Cache::put("fake-agent.job.{$job}", ['status' => 'succeeded', 'log' => ['step: website zip', 'step: database export', 'done'],
                'result' => self::report($p['import'] ?? '', $p['domain'] ?? 'example.nl')], 3600);

            return ['job' => $job];
        });
        $agent->respond('wp_import.run', function (array $p) {
            $job = 'fake-wpi-run-'.md5(($p['import'] ?? '').microtime());
            $name = str_replace(['.', '-'], '_', $p['domain'] ?? 'example_nl').'_import_7c1e';
            Cache::put("fake-agent.job.{$job}", ['status' => 'succeeded',
                'log' => ['step: files', 'files: unpacking 6214 entries', 'step: database', 'step: config', 'step: replace', 'replace: 1873 values changed', 'step: check', 'done in 41 s'],
                'result' => ['domain' => $p['domain'] ?? '', 'url' => $p['new_url'] ?? '', 'database' => ['name' => $name, 'user' => $name, 'host' => 'localhost'],
                    'replacements' => 1873, 'disabled' => $p['disable'] ?? [], 'health' => 200, 'dropped_databases' => [], 'size_mb' => 412]], 3600);

            return ['job' => $job];
        });
        $agent->respond('wp_import.discard', fn (array $p) => ['import' => $p['import'] ?? '', 'removed' => 3]);
        // job.status answers our own jobs; everything else goes to whoever answered before us.
        $previous = (fn () => $this->responses['job.status'] ?? null)->call($agent);
        $agent->respond('job.status', function (array $p) use ($previous) {
            $job = (string) ($p['job'] ?? '');
            $state = str_starts_with($job, 'fake-wpi-') ? Cache::get("fake-agent.job.{$job}") : null;
            if ($state) {
                return ['id' => $job, 'error' => null] + $state;
            }
            if ($previous instanceof \Closure) {
                return $previous($p);
            }

            return is_array($previous) ? $previous
                : ['id' => $job, 'status' => 'succeeded', 'log' => ['Done'], 'result' => [], 'error' => null];
        });
    }

    /** A realistic check report: a site from a cPanel host with a cache plugin and a host plugin. */
    public static function report(string $import, string $domain): array
    {
        $root = "/var/www/vhosts/{$domain}/public";
        $old = '/home/klant123/public_html';

        return [
            'import' => $import, 'domain' => $domain, 'checked_at' => time(), 'ok' => true,
            'checks' => [
                ['key' => 'zip_valid', 'state' => 'ok', 'detail' => '6214 entries'],
                ['key' => 'wordpress_found', 'state' => 'ok', 'detail' => 'WordPress 6.8.3', 'folder' => 'public_html', 'version' => '6.8.3'],
                ['key' => 'config_found', 'state' => 'ok', 'detail' => 'public_html/wp-config.php'],
                ['key' => 'database_found', 'state' => 'ok', 'detail' => '38 tables', 'file' => 'klant123_wp.sql'],
                ['key' => 'prefix_matches', 'state' => 'ok', 'detail' => 'wp_'],
            ],
            'site' => [
                'entries' => 6214, 'size' => 402 * 1024 ** 2, 'skipped_size' => 61 * 1024 ** 2, 'folder' => 'public_html', 'outside_entries' => 12,
                'version' => '6.8.3', 'config' => 'public_html/wp-config.php', 'from_sample' => false, 'multisite' => false, 'table_prefix' => 'wp_',
                'old_db' => ['db_name' => 'klant123_wp', 'db_user' => 'klant123_wp', 'db_host' => 'localhost', 'password_set' => true],
                'cache' => [
                    ['file' => 'wp-content/advanced-cache.php', 'kind' => 'page_cache', 'disable' => false],
                    ['file' => 'wp-content/object-cache.php', 'kind' => 'dropin', 'disable' => true],
                    ['file' => 'wp-content/mu-plugins/endurance-page-cache.php', 'kind' => 'host_plugin', 'disable' => true],
                ],
                'cache_defines' => ['WPCACHEHOME', 'WP_CACHE', 'WP_REDIS_HOST'], 'home_define' => null, 'siteurl_define' => null,
            ],
            'database' => [
                'file' => 'klant123_wp.sql', 'size' => 48 * 1024 ** 2, 'tables' => 38, 'prefix' => 'wp_', 'prefixes' => ['wp_'],
                'siteurl' => "https://www.{$domain}", 'home' => "https://www.{$domain}", 'create_database' => 1, 'use_database' => 1,
                'super_statements' => 0, 'mysql8_collation' => true,
            ],
            'paths' => [
                ['old' => $old, 'count' => 214, 'new' => $root, 'likely' => true],
                ['old' => '/home/klant123/tmp', 'count' => 3, 'new' => $root, 'likely' => true],
            ],
            'urls' => [
                ['old' => "http://www.{$domain}", 'new' => "https://www.{$domain}"],
                ['old' => "http://{$domain}", 'new' => "https://www.{$domain}"],
                ['old' => "https://{$domain}", 'new' => "https://www.{$domain}"],
            ],
            'target' => [
                'docroot' => $root, 'url' => "https://www.{$domain}", 'urls' => ["https://{$domain}", "https://www.{$domain}"],
                'content' => [], 'database' => str_replace(['.', '-'], '_', $domain).'_import_7c1e', 'previous_databases' => [],
            ],
        ];
    }
}
