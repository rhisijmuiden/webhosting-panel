<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Extensions\ExtensionManager;
use Rhis\WordPress\Models\WordPressInstall;

/** Plugins & themes page, shared by admins and customers; the subclasses decide who may see which install. */
abstract class ExtensionsController extends Controller
{
    /** 'admin' or 'client'. */
    protected string $area;

    public function __construct(protected readonly ExtensionManager $manager) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    private function back(WordPressInstall $install, string $tab, array $query = []): RedirectResponse
    {
        return redirect()->route("{$this->area}.wordpress.extensions.index", ['install' => $install, 'tab' => $tab] + $query);
    }

    public function index(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $tab = in_array($request->query('tab'), ['plugins', 'themes', 'add'], true) ? $request->query('tab') : 'plugins';
        $agentErrors = [];
        $search = null;
        $kind = $request->query('kind') === 'theme' ? 'theme' : 'plugin';
        $q = trim((string) $request->query('q', ''));
        $page = max(1, min(50, (int) $request->query('page', 1)));

        if ($tab !== 'add') {
            try {
                $this->manager->load($install);
            } catch (AgentException $e) {
                $agentErrors[] = $e->getMessage();
            }
        } elseif (mb_strlen($q) >= 2) {
            try {
                $search = $this->manager->search($install, $kind, mb_substr($q, 0, 100), $page);
            } catch (AgentException $e) {
                $agentErrors[] = $e->getMessage();
            }
        }

        return view('wordpress::extensions.page', [
            'install' => $install->loadMissing('website'), 'area' => $this->area, 'tab' => $tab, 'kind' => $kind, 'q' => $q,
            'page' => $page, 'search' => $search, 'agentErrors' => $agentErrors,
            'plugins' => collect($install->plugins ?? [])->sortBy(fn ($p) => mb_strtolower($p['title'] ?: $p['name']))->values(),
            'themes' => collect($install->themes ?? [])->sortBy(fn ($t) => [$t['status'] === 'active' ? 0 : ($t['status'] === 'parent' ? 1 : 2), mb_strtolower($t['title'] ?: $t['name'])])->values(),
        ]);
    }

    public function apply(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        // A row button sends "op|slug"; the bulk bar sends op + slugs[].
        if ($request->filled('row')) {
            [$op, $slug] = array_pad(explode('|', (string) $request->input('row'), 2), 2, '');
            $request->merge(['op' => $op, 'slugs' => [$slug]]);
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(ExtensionManager::KINDS)],
            'op' => ['required', 'string'],
            'slugs' => ['required', 'array', 'min:1', 'max:200'],
            'slugs.*' => ['string', 'regex:'.ExtensionManager::SLUG],
        ], ['slugs.required' => __('Select at least one item.')]);
        $tab = $data['kind'].'s';
        if (! in_array($data['op'], ExtensionManager::OPS[$data['kind']], true)) {
            return $this->back($install, $tab)->withErrors(['agent' => __('Choose an action.')]);
        }
        if ($data['kind'] === 'theme' && $data['op'] === 'activate' && count($data['slugs']) > 1) {
            return $this->back($install, $tab)->withErrors(['agent' => __('Only one theme can be active.')]);
        }
        try {
            $out = $this->manager->apply($install, $data['kind'], $data['op'], $data['slugs']);
        } catch (AgentException $e) {
            return $this->back($install, $tab)->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record("wordpress.{$data['kind']}.{$data['op']}", $install->label(), ['slugs' => $data['slugs'], 'done' => $out['done'] ?? []], $install->website->customer_id);

        return $this->outcome($this->back($install, $tab), $out, $data['kind'], $data['op']);
    }

    public function install(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'kind' => ['required', Rule::in(ExtensionManager::KINDS)],
            'slug' => ['required', 'string', 'regex:'.ExtensionManager::SLUG],
            'activate' => ['sometimes', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $activate = (bool) ($data['activate'] ?? false);
        try {
            $out = $this->manager->install($install, $data['kind'], $data['slug'], $activate);
        } catch (AgentException $e) {
            return $this->back($install, 'add', ['kind' => $data['kind'], 'q' => $data['q'] ?? null])->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record("wordpress.{$data['kind']}.install", $install->label(), ['slug' => $data['slug'], 'activate' => $activate], $install->website->customer_id);

        $back = $this->back($install, $data['kind'].'s')->with('highlight', $data['slug']);

        return $this->outcome($back, $out, $data['kind'], $activate ? 'install_activate' : 'install');
    }

    /** Flash what happened: a success line, the errors, and a clear warning after an automatic rollback. */
    private function outcome(RedirectResponse $back, array $out, string $kind, string $op): RedirectResponse
    {
        $done = $out['done'] ?? [];
        $errors = collect($out['errors'] ?? [])->map(fn ($e) => ($e['slug'] ?? '').': '.($e['error'] ?? ''))->all();
        if ($out['rolled_back'] ?? false) {
            $back->with('wp_rolled_back', ['health' => $out['health'] ?? null]);
        }
        if ($done) {
            $names = implode(', ', $done);
            $messages = [
                'activate' => __(':names activated.', ['names' => $names]),
                'deactivate' => __(':names deactivated.', ['names' => $names]),
                'delete' => __(':names deleted.', ['names' => $names]),
                'auto_update_on' => __('Automatic updates switched on for :names.', ['names' => $names]),
                'auto_update_off' => __('Automatic updates switched off for :names.', ['names' => $names]),
                'install' => __(':names installed.', ['names' => $names]),
                'install_activate' => ($out['activated'] ?? false) ? __(':names installed and activated.', ['names' => $names]) : __(':names installed.', ['names' => $names]),
            ];
            $back->with('status', $messages[$op] ?? __('Done.'));
        }

        return $errors ? $back->withErrors(['items' => $errors]) : $back;
    }
}
