<?php

declare(strict_types=1);

namespace Rhis\ObjectCache\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\ObjectCache\ObjectCacheManager;

/** Switch the object cache of a website on or off and flush it (shared by admin and client). */
abstract class ObjectCacheController extends Controller
{
    public function __construct(protected readonly ObjectCacheManager $manager) {}

    abstract protected function authorizeWebsite(Request $request, Website $website): void;

    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'wordpress' => ['sometimes', 'boolean']]);
        $enabled = (bool) $data['enabled'];
        if ($enabled && ! ObjectCacheManager::allowed($website)) {
            return back()->withErrors(['object_cache' => __('The package of this website has no object cache.')]);
        }
        if ($enabled && $website->status !== 'active') {
            return back()->withErrors(['object_cache' => __('The website must be online to switch on the object cache.')]);
        }
        $this->manager->request($website, $enabled, (bool) ($data['wordpress'] ?? true));
        ActivityLog::record($enabled ? 'object_cache.enabled' : 'object_cache.disabled', $website->domain, [], $website->customer_id);

        return back()->with('status', $enabled
            ? __('The object cache is being switched on. This takes up to a minute for WordPress.')
            : __('The object cache is being switched off.'));
    }

    public function flush(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        try {
            $out = $this->manager->flush($website);
        } catch (AgentException $e) {
            return back()->withErrors(['object_cache' => __('The cache could not be emptied: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('object_cache.flushed', $website->domain, [], $website->customer_id);

        return back()->with('status', __('Object cache emptied (:count items removed).', ['count' => (int) ($out['keys_removed'] ?? 0)]));
    }
}
