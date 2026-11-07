<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Settings;
use App\Support\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The hosting company name customers see ("Let RHIS run your DNS"). */
class BrandingController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(): View
    {
        return view('admin.branding.edit', [
            'providerName' => Brand::provider(),
            'default' => (string) config('panel.brand.provider'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider' => ['required', 'string', 'max:40', 'regex:/^[^<>{}]+$/u']]);
        $name = trim($data['provider']);
        $this->settings->set('brand.provider', $name);
        ActivityLog::record('settings.branding_updated', $name);

        return redirect()->route('admin.branding.edit')->with('status', __('Saved.'));
    }
}
