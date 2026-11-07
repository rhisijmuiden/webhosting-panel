<?php

declare(strict_types=1);

namespace Rhis\ObjectCache;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Rhis\ObjectCache\Jobs\ApplyObjectCache;
use Rhis\ObjectCache\Models\ObjectCache;

/** Everything the panel asks the agent about the object cache of a website. */
final class ObjectCacheManager
{
    public const MIN_MB = 16;

    public const MAX_MB = 65536;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Memory one object cache of this website gets (MB), or null when its package does not
     * offer it. Package value: NULL = server default, 0 = not available.
     */
    public static function memoryFor(Website $website): ?int
    {
        $value = $website->package?->object_cache_mb;
        if ($value === 0) {
            return null;
        }
        $mb = $value ?? (int) config('object-cache.default_mb', 64);

        return max(self::MIN_MB, min(self::MAX_MB, $mb));
    }

    public static function allowed(Website $website): bool
    {
        return self::memoryFor($website) !== null;
    }

    public function for(Website $website): ObjectCache
    {
        return ObjectCache::firstOrNew(['website_id' => $website->id]);
    }

    /** Switch on or off (or change the WordPress setting). The work runs in the queue. */
    public function request(Website $website, bool $enabled, bool $wordpress = true): ObjectCache
    {
        $cache = $this->for($website);
        $cache->fill(['enabled' => $enabled, 'wordpress' => $wordpress, 'state' => 'applying', 'last_error' => null])->save();
        ApplyObjectCache::dispatch($cache->id);

        return $cache;
    }

    /** Write the wanted state to the server. Throws AgentException (also stored as last_error). */
    public function apply(ObjectCache $cache): array
    {
        $website = $cache->website;
        $memory = self::memoryFor($website);
        $enabled = $cache->enabled && $memory !== null;
        try {
            $out = $this->agent->call('objcache.apply', array_filter([
                'domain' => $website->domain, 'enabled' => $enabled, 'memory_mb' => $enabled ? $memory : null,
                'wordpress' => $cache->wordpress,
            ], fn ($v) => $v !== null), 900);
        } catch (AgentException $e) {
            $cache->update(['state' => 'failed', 'last_error' => $e->getMessage()]);
            throw $e;
        }
        $cache->update([
            'enabled' => $enabled,
            'memory_mb' => $enabled ? $memory : $cache->memory_mb,
            'state' => $enabled ? (($out['state'] ?? 'running') === 'running' ? 'running' : ($out['state'] ?? 'stopped')) : 'off',
            'installs' => $out['wordpress'] ?? [],
            'last_error' => collect($out['wordpress'] ?? [])->pluck('error')->filter()->first(),
        ]);

        return $out;
    }

    /** Apply again later (package change, unsuspend), only for caches that are on. */
    public function reapply(Website $website): void
    {
        $cache = ObjectCache::where('website_id', $website->id)->first();
        if (! $cache || ! $cache->enabled) {
            return;
        }
        $cache->update(['state' => 'applying']);
        ApplyObjectCache::dispatch($cache->id);
    }

    /** Live status from the server, or null when the agent does not answer. */
    public function status(Website $website): ?array
    {
        try {
            return $this->agent->call('objcache.status', ['domain' => $website->domain], 10);
        } catch (AgentException) {
            return null;
        }
    }

    /** Live status of many websites: [domain => status]. */
    public function statuses(Collection $websites): array
    {
        if ($websites->isEmpty()) {
            return [];
        }
        try {
            return $this->agent->call('objcache.list', ['domains' => $websites->pluck('domain')->values()->all()], 30)['sites'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }

    /** Empty the cache. Throws AgentException. */
    public function flush(Website $website): array
    {
        $out = $this->agent->call('objcache.flush', ['domain' => $website->domain], 30);
        ObjectCache::where('website_id', $website->id)->update(['flushed_at' => now()]);

        return $out;
    }

    public function suspend(Website $website, bool $suspended): void
    {
        if (! ObjectCache::where('website_id', $website->id)->where('enabled', true)->exists()) {
            return;
        }
        try {
            $this->agent->call('objcache.suspend', ['domain' => $website->domain, 'suspended' => $suspended], 60);
        } catch (AgentException $e) {
            Log::warning('objcache.suspend failed', ['domain' => $website->domain, 'error' => $e->getMessage()]);
        }
    }

    public function remove(Website $website): void
    {
        if (! ObjectCache::where('website_id', $website->id)->exists()) {
            return;
        }
        try {
            $this->agent->call('objcache.remove', ['domain' => $website->domain], 300);
        } catch (AgentException $e) {
            Log::warning('objcache.remove failed', ['domain' => $website->domain, 'error' => $e->getMessage()]);
        }
    }
}
