<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use App\Services\Agent\FakeAgentClient;

/** wp.dbclean_* answers for the fake agent (local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp.dbclean_preview', fn (array $p) => self::preview($p));
        $agent->respond('wp.dbclean_run', fn (array $p) => ['job' => 'fake-dbclean-'.substr(md5(json_encode($p)), 0, 12)]);
    }

    public static function preview(array $p): array
    {
        $keep = (int) ($p['keep_revisions'] ?? 0);
        $revisions = max(0, 1284 - $keep * 160);

        return [
            'domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '', 'checked_at' => time(),
            'prefix' => 'wp_', 'multisite' => false, 'db_size' => 187 * 1024 * 1024, 'tables' => 38, 'keep_revisions' => $keep,
            'items' => [
                'revisions' => ['count' => $revisions, 'bytes' => $revisions * 21500, 'examples' => [
                    ['label' => 'Home', 'count' => (int) round($revisions * 0.31)],
                    ['label' => 'Over ons', 'count' => (int) round($revisions * 0.12)],
                    ['label' => 'Contact', 'count' => (int) round($revisions * 0.07)],
                ]],
                'auto_drafts' => ['count' => 23, 'bytes' => 4100, 'examples' => []],
                'trashed_posts' => ['count' => 6, 'bytes' => 38_000, 'examples' => [
                    ['label' => 'Zomeractie 2025', 'type' => 'page'], ['label' => 'Test bericht', 'type' => 'post'],
                ]],
                'spam_comments' => ['count' => 4812, 'bytes' => 3_900_000, 'examples' => [['label' => 'cheap-pills'], ['label' => 'SEO Expert']]],
                'trashed_comments' => ['count' => 17, 'bytes' => 9800, 'examples' => []],
                'expired_transients' => ['count' => 642, 'bytes' => 6_400_000, 'examples' => []],
                'all_transients' => ['count' => 910, 'bytes' => 9_100_000, 'examples' => []],
                'orphaned_meta' => ['count' => 3307, 'bytes' => 0, 'examples' => [], 'post_meta' => 3290, 'comment_meta' => 17],
                'optimize' => ['count' => 7, 'bytes' => 41 * 1024 * 1024, 'examples' => [
                    ['label' => 'wp_options', 'bytes' => 18 * 1024 * 1024], ['label' => 'wp_postmeta', 'bytes' => 12 * 1024 * 1024],
                    ['label' => 'wp_posts', 'bytes' => 7 * 1024 * 1024],
                ]],
            ],
        ];
    }
}
