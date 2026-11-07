<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ApplySubscriptionLimits;
use App\Models\ActivityLog;
use App\Models\HostingPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['active', 'inactive'], true) ? $request->query('tab') : 'all';
        $search = trim((string) $request->query('q'));
        $packages = HostingPackage::withCount('subscriptions')
            ->when($tab !== 'all', fn ($q) => $q->where('is_active', $tab === 'active'))
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->get();

        return view('admin.packages.index', [
            'packages' => $packages,
            'tab' => $tab,
            'search' => $search,
            'counts' => ['all' => HostingPackage::count(), 'active' => HostingPackage::where('is_active', true)->count(), 'inactive' => HostingPackage::where('is_active', false)->count()],
        ]);
    }

    /** A copy of a package to start a new one from; it is inactive and has no CRM link yet. */
    public function duplicate(HostingPackage $package): RedirectResponse
    {
        $copy = $package->replicate(['subscriptions_count']);
        $copy->name = __(':name (copy)', ['name' => $package->name]);
        $copy->crm_id = null;
        $copy->is_active = false;
        $copy->save();
        ActivityLog::record('package.created', $copy->name);

        return redirect()->route('admin.packages.edit', $copy)->with('status', __('Package copied. Check the name and limits, then activate it.'));
    }

    public function create(): View
    {
        // Unlimited by default; a package only gets the limits the admin fills in.
        return view('admin.packages.form', ['package' => new HostingPackage(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $package = HostingPackage::create($this->validated($request));
        ActivityLog::record('package.created', $package->name);

        return redirect()->route('admin.packages.index')->with('status', __('Package created.'));
    }

    public function edit(HostingPackage $package): View
    {
        return view('admin.packages.form', compact('package'));
    }

    public function update(Request $request, HostingPackage $package): RedirectResponse
    {
        $package->update($this->validated($request, $package));
        ActivityLog::record('package.updated', $package->name);
        if ($package->wasChanged(['cpu_percent', 'memory_mb', 'io_mbps', 'max_processes', 'php_workers', 'db_connections'])) {
            foreach ($package->subscriptions as $subscription) {
                ApplySubscriptionLimits::dispatch($subscription);
            }
        }

        return redirect()->route('admin.packages.index')->with('status', __('Package saved.'));
    }

    public function destroy(HostingPackage $package): RedirectResponse
    {
        if ($package->subscriptions()->exists()) {
            return back()->withErrors(['package' => __('This package is still in use.')]);
        }
        ActivityLog::record('package.deleted', $package->name);
        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', __('Package deleted.'));
    }

    private function validated(Request $request, ?HostingPackage $package = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'crm_id' => ['nullable', 'string', 'max:64', Rule::unique('hosting_packages')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'traffic_action' => ['required', Rule::in(HostingPackage::TRAFFIC_ACTIONS)],
            'unlimited' => ['array'],
        ];
        foreach (HostingPackage::USAGE_LIMITS + HostingPackage::RESOURCE_LIMITS as $key => [, , $min, $max]) {
            $rules[$key] = $request->boolean("unlimited.$key")
                ? ['nullable']
                : ['required', 'integer', "min:$min", "max:$max"];
        }
        // App limits are optional: missing (older forms, the CRM API) means unlimited.
        foreach (HostingPackage::APP_LIMITS + HostingPackage::CACHE_LIMITS as $key => [, , $min, $max]) {
            $rules[$key] = $request->boolean("unlimited.$key") || ! $request->filled($key)
                ? ['nullable']
                : ['integer', "min:$min", "max:$max"];
        }
        $data = $request->validate($rules);
        unset($data['unlimited']);
        foreach (array_keys(HostingPackage::APP_LIMITS + HostingPackage::CACHE_LIMITS) as $key) {
            $data[$key] = $request->boolean("unlimited.$key") || ! $request->filled($key) ? null : (int) $data[$key];
        }
        foreach (HostingPackage::limitKeys() as $key) {
            $data[$key] = $request->boolean("unlimited.$key") ? null : (int) $data[$key];
        }

        return $data + ['allow_ssh' => $request->boolean('allow_ssh'), 'is_active' => $request->boolean('is_active')];
    }
}
