<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Answers for wp.smart_* when the panel runs without a real agent (WHP_AGENT_DRIVER=fake):
 * a smart update that finishes at once. Sites whose name contains "shop" look different
 * after the update and are rolled back, so the screens have both outcomes to show.
 * Screenshots: JPEGs from WHP_FAKE_SMART_IMAGES (a folder) when set, else a tiny placeholder.
 */
final class FakeSmartUpdates
{
    private const PIXEL = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wp.smart_check', ['chromium' => true, 'pillow' => true, 'user' => true, 'visual' => true]);
        $agent->respond('wp.smart_update', function (array $p) {
            $job = (string) Str::uuid();
            Cache::put("fake-agent.smart.$job", self::result($p), 3600);

            return ['job' => $job];
        });
        $agent->respond('job.status', function (array $p) {
            $id = (string) ($p['job'] ?? '');
            if ($state = Cache::get("fake-agent.smart.$id")) {
                return ['id' => $id, 'status' => 'succeeded', 'log' => $state['log'], 'result' => $state['result'], 'error' => null];
            }

            return ['id' => $id, 'status' => 'succeeded', 'log' => ['Done'], 'result' => [], 'error' => null];
        });
        $agent->respond('wp.smart_image', function (array $p) {
            $dir = env('WHP_FAKE_SMART_IMAGES');
            $file = $dir ? rtrim((string) $dir, '/').'/'.basename((string) $p['name']) : null;
            $data = $file && is_file($file) ? base64_encode((string) file_get_contents($file)) : self::PIXEL;

            return ['name' => $p['name'], 'type' => 'image/jpeg', 'data' => $data];
        });
        $agent->respond('wp.smart_forget', fn (array $p) => ['run' => $p['run'] ?? '', 'removed' => true]);
    }

    private static function result(array $p): array
    {
        $mode = $p['mode'] ?? 'all';
        $broken = str_contains((string) ($p['domain'] ?? ''), 'shop');
        $pending = [
            'core' => ['from' => '6.6.1', 'to' => $mode === 'security' ? '6.6.2' : '6.7.1'],
            'plugins' => [
                ['name' => 'woocommerce', 'title' => 'WooCommerce', 'from' => '9.3.1', 'to' => '9.4.0'],
                ['name' => 'contact-form-7', 'title' => 'Contact Form 7', 'from' => '5.9.6', 'to' => '5.9.8'],
            ],
            'themes' => [['name' => 'astra', 'title' => 'Astra', 'from' => '4.7.3', 'to' => '4.8.1']],
        ];
        if ($mode === 'notify') {
            return ['log' => ['Checking for updates', '4 updates waiting'], 'result' => [
                'outcome' => 'pending', 'mode' => 'notify', 'pending' => $pending, 'pages' => [], 'visual' => false,
            ]];
        }
        $updated = ['core' => ['old_version' => '6.6.1', 'new_version' => $pending['core']['to']],
            'plugins' => $mode === 'security'
                ? [['name' => 'contact-form-7', 'old_version' => '5.9.6', 'new_version' => '5.9.8']]
                : [['name' => 'woocommerce', 'old_version' => '9.3.1', 'new_version' => '9.4.0'], ['name' => 'contact-form-7', 'old_version' => '5.9.6', 'new_version' => '5.9.8']],
            'themes' => $mode === 'security' ? [] : [['name' => 'astra', 'old_version' => '4.7.3', 'new_version' => '4.8.1']], 'translations' => true];
        $pages = [];
        foreach ($p['pages'] ?? ['/'] as $i => $page) {
            foreach ($p['viewports'] ?? ['desktop', 'mobile'] as $vp) {
                $score = $broken && $i === 0 && $vp === 'desktop' ? 37.4 : ($vp === 'mobile' ? 0.8 : 1.6);
                $images = ['before' => "p$i-$vp-before.jpg", 'after' => "p$i-$vp-after.jpg", 'diff' => "p$i-$vp-diff.jpg"];
                if ($broken) {
                    $images['restored'] = "p$i-$vp-restored.jpg";
                }
                $pages[] = ['page' => $page, 'viewport' => $vp, 'score' => $score, 'noise' => 4.2, 'blank' => false,
                    'size_changed' => false, 'images' => $images, 'restored_score' => $broken ? 0.4 : null];
            }
        }
        $max = max(array_column($pages, 'score') ?: [0]);
        $threshold = (float) ($p['threshold'] ?? 10);
        $rolled = $max > $threshold;

        return ['log' => array_values(array_filter([
            'Screenshots before the update (desktop, mobile)', 'Snapshot made', 'Updating WordPress core', 'Updating plugins',
            'Screenshots after the update', "Largest visual difference: $max%; threshold $threshold%",
            $rolled ? 'The website looked different after the update and was rolled back' : null,
        ])), 'result' => [
            'outcome' => $rolled ? 'rolled_back' : 'updated', 'reason' => $rolled ? 'visual' : null, 'mode' => $mode,
            'pending' => $pending, 'updated' => $updated, 'pages' => $pages, 'max_score' => $max, 'threshold' => $threshold,
            'visual' => true, 'visual_errors' => [], 'errors' => [], 'health' => ['before' => 200, 'after' => 200],
            'snapshot' => now()->format('Ymd-His'),
        ]];
    }
}
