<?php

declare(strict_types=1);

namespace Rhis\WordPress\Http;

use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class AdminWordPressController extends WordPressController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void {}

    protected function customerId(Request $request): ?int
    {
        return null;
    }

    /** Look for WordPress on all websites now (also runs every night). */
    public function scan(Request $request): RedirectResponse
    {
        $data = $request->validate(['website' => ['nullable', 'integer']]);
        $sites = Website::where('status', 'active')->when($data['website'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
        $found = 0;
        $failed = [];
        foreach ($sites as $site) {
            try {
                $found += count($this->toolkit->discover($site));
            } catch (\App\Services\Agent\AgentException $e) {
                $failed[] = $site->domain;
            }
        }
        $back = back()->with('status', __(':n WordPress installs found and checked.', ['n' => $found]));

        return $failed ? $back->withErrors(['agent' => __('Could not check: :sites', ['sites' => implode(', ', $failed)])]) : $back;
    }
}
