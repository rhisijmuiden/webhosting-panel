<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use Rhis\ObjectCache\Models\ObjectCache;
use Rhis\ObjectCache\ObjectCacheManager;
use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;

/** Empty the Valkey object cache of each website (packages/object-cache). */
final class FlushObjectCache extends BaseAction
{
    public function key(): string
    {
        return 'flush-object-cache';
    }

    public function label(): string
    {
        return __('Empty the object cache');
    }

    public function description(): string
    {
        return __('Empty the Valkey object cache of each website. Sites without an object cache are skipped.');
    }

    public function group(): string
    {
        return 'maintenance';
    }

    public function icon(): string
    {
        return 'bolt';
    }

    public function order(): int
    {
        return 30;
    }

    public function perWebsite(): bool
    {
        return true;
    }

    public function concurrency(): int
    {
        return 5;
    }

    public function skipReason(WordPressInstall $install, array $options): ?string
    {
        return ObjectCache::where('website_id', $install->website_id)->where('enabled', true)->exists()
            ? null : __('This website has no object cache.');
    }

    public function start(WordPressInstall $install, array $options): Step
    {
        $out = app(ObjectCacheManager::class)->flush($install->website);

        return Step::ok(isset($out['keys_removed'])
            ? trans_choice(':count key removed.|:count keys removed.', (int) $out['keys_removed'])
            : __('Emptied.'));
    }
}
