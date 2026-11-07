<?php

declare(strict_types=1);

namespace Rhis\ObjectCache\Http;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\ObjectCache\Models\ObjectCache;
use Rhis\ObjectCache\ObjectCacheManager;

/** Admins: every object cache on the server, and the same switch and flush as customers. */
final class AdminObjectCacheController extends ObjectCacheController
{
    protected function authorizeWebsite(Request $request, Website $website): void {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $caches = ObjectCache::with('website.customer')->where('enabled', true)
            ->whereHas('website', fn ($q) => $q->when($search, fn ($q) => $q->where('domain', 'like', "%{$search}%")))
            ->get()->sortBy('website.domain')->values();
        $live = $this->manager->statuses($caches->pluck('website'));

        return view('object-cache::admin.index', [
            'caches' => $caches,
            'live' => $live,
            'search' => $search,
            'totalMemory' => collect($live)->sum(fn ($s) => $s['stats']['used_memory_bytes'] ?? 0),
            'totalMax' => $caches->sum(fn (ObjectCache $c) => ($c->memory_mb ?? 0) * 1024 * 1024),
            'defaultMb' => (int) config('object-cache.default_mb', 64),
            'manager' => ObjectCacheManager::class,
        ]);
    }
}
