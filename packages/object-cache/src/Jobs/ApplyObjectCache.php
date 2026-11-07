<?php

declare(strict_types=1);

namespace Rhis\ObjectCache\Jobs;

use App\Services\Agent\AgentException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rhis\ObjectCache\Models\ObjectCache;
use Rhis\ObjectCache\ObjectCacheManager;

/** Start, reconfigure or stop the object cache of a website (installing the WordPress plugin can take a minute). */
final class ApplyObjectCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 960;

    public int $tries = 1;

    public function __construct(public readonly int $cacheId) {}

    public function handle(ObjectCacheManager $manager): void
    {
        $cache = ObjectCache::with('website')->find($this->cacheId);
        if (! $cache || ! $cache->website) {
            return;
        }
        try {
            $manager->apply($cache);
        } catch (AgentException) {
            // Stored on the record (state failed, last_error); the page shows it.
        }
    }
}
