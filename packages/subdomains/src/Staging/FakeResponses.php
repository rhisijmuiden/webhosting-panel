<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging;

use App\Services\Agent\FakeAgentClient;

/** wp.staging_* answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp.staging_prepare', fn (array $p) => ['staging' => $p['staging'] ?? '', 'path' => $p['path'] ?? '',
            'mu_plugin' => 'wp-content/mu-plugins/whp-staging.php']);
        $agent->respond('wp.staging_plan', fn (array $p) => [
            'staging' => $p['staging'] ?? '', 'live' => $p['live'] ?? '', 'path' => $p['path'] ?? '', 'problems' => [],
            'staging_url' => 'https://'.($p['staging'] ?? ''), 'live_url' => 'https://'.($p['live'] ?? ''),
            'staging_db' => 'staging_wp_9f3c', 'live_db' => 'example_wp_1a2b', 'prefix' => 'wp_',
            'tables' => [
                ['name' => 'wp_options', 'size_mb' => 2.1, 'on_live' => true],
                ['name' => 'wp_posts', 'size_mb' => 14.6, 'on_live' => true],
                ['name' => 'wp_postmeta', 'size_mb' => 9.2, 'on_live' => true],
                ['name' => 'wp_users', 'size_mb' => 0.1, 'on_live' => true],
                ['name' => 'wp_usermeta', 'size_mb' => 0.2, 'on_live' => true],
                ['name' => 'wp_wc_orders', 'size_mb' => 3.4, 'on_live' => true],
            ],
            'live_only_tables' => [], 'suggested_keep' => ['wp_users', 'wp_usermeta', 'wp_wc_orders'], 'woocommerce' => true,
            'size_mb' => 184, 'backups' => 1,
        ]);
        $agent->respond('wp.staging_backups', fn (array $p) => ['live' => $p['live'] ?? '', 'keep' => 3, 'backups' => [
            ['id' => '20261107-021500', 'live' => $p['live'] ?? '', 'path' => '', 'created' => 1793931300,
                'reason' => 'before push from staging.'.($p['live'] ?? ''), 'database' => 'example_wp_1a2b', 'tables' => ['wp_options'],
                'size_mb' => 190, 'complete' => true],
        ]]);
        $agent->respond('wp.staging_push', fn (array $p) => ['job' => 'fake-push-'.md5(($p['staging'] ?? '').($p['live'] ?? ''))]);
        $agent->respond('wp.staging_restore', fn (array $p) => ['job' => 'fake-restore-'.md5(($p['live'] ?? '').($p['backup'] ?? ''))]);
    }
}
