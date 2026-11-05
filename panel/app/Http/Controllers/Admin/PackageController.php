<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return view('admin.packages.form', ['package' => new HostingPackage(['is_active' => true, 'disk_mb' => 10240, 'max_websites' => 1, 'max_mailboxes' => 10, 'max_databases' => 5, 'max_cronjobs' => 5])]);
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'crm_id' => ['nullable', 'string', 'max:64', Rule::unique('hosting_packages')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'disk_mb' => ['required', 'integer', 'min:100', 'max:10000000'],
            'max_websites' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_mailboxes' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_cronjobs' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        return $data + ['allow_ssh' => $request->boolean('allow_ssh'), 'is_active' => $request->boolean('is_active')];
    }
}
