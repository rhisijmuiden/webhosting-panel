<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bulk actions on the admin domain list: certificates, page cache and removal. */
class WebsiteBulkController extends Controller
{
    public const ACTIONS = ['ssl', 'cache_on', 'cache_off', 'delete'];

    public function __invoke(Request $request, WebsiteManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'confirm' => [Rule::requiredIf($request->input('action') === 'delete'), 'nullable', 'string'],
        ], ['ids.required' => __('Select at least one domain.')]);

        if ($data['action'] === 'delete' && mb_strtolower(trim((string) $data['confirm'])) !== mb_strtolower(__('delete'))) {
            return back()->withErrors(['confirm' => __('Type :word to confirm.', ['word' => __('delete')])]);
        }

        $websites = Website::whereIn('id', $data['ids'])->get();
        $done = 0;
        $failed = [];
        foreach ($websites as $website) {
            try {
                match ($data['action']) {
                    'ssl' => $manager->issueSsl($website),
                    'cache_on' => $website->page_cache ? null : $manager->setCache($website, true),
                    'cache_off' => $website->page_cache ? $manager->setCache($website, false) : null,
                    'delete' => $manager->remove($website, $request->boolean('keep_files')),
                };
                $done++;
            } catch (AgentException $e) {
                $failed[] = $website->domain.': '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('admin.websites.index', $request->only('q', 'status', 'php', 'sort', 'dir', 'per_page'))
            ->with('status', trans_choice(':count domain done.|:count domains done.', $done));

        return $failed ? $redirect->withErrors(['agent' => implode("\n", $failed)]) : $redirect;
    }
}
