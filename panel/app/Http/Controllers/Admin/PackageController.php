<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyCustomerLimits;
use App\Models\ActivityLog;
use App\Models\HostingPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('admin.packages.index', ['packages' => HostingPackage::withCount('customers')->orderBy('name')->get()]);
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
            foreach ($package->customers as $customer) {
                ApplyCustomerLimits::dispatch($customer);
            }
        }

        return redirect()->route('admin.packages.index')->with('status', __('Package saved.'));
    }

    public function destroy(HostingPackage $package): RedirectResponse
    {
        if ($package->customers()->exists()) {
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
        $data = $request->validate($rules);
        unset($data['unlimited']);
        foreach (HostingPackage::limitKeys() as $key) {
            $data[$key] = $request->boolean("unlimited.$key") ? null : (int) $data[$key];
        }

        return $data + ['allow_ssh' => $request->boolean('allow_ssh'), 'is_active' => $request->boolean('is_active')];
    }
}
