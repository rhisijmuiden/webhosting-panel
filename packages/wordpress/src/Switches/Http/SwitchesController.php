<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Switches\Switches;

/** Quick switches and the debug log viewer. One controller for both areas: the route name says which. */
final class SwitchesController extends Controller
{
    public function __construct(private readonly Switches $switches) {}

    private function area(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin' : 'client';
    }

    private function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        if ($this->area($request) === 'admin') {
            return;
        }
        abort_unless(config('wordpress.customer_toolkit'), 404);
        abort_unless($install->website && $install->website->customer_id === $request->user()->customer_id, 404);
    }

    private function log(string $action, WordPressInstall $install, array $props = []): void
    {
        ActivityLog::record("wordpress.{$action}", $install->label(), $props, $install->website?->customer_id);
    }

    private function failed(AgentException $e): RedirectResponse
    {
        return back()->withErrors(['agent' => $e->getMessage()]);
    }

    public function maintenance(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $on = (bool) $data['enabled'];
        try {
            $this->switches->set($install, 'maintenance', $on, [
                'title' => trim((string) ($data['title'] ?? '')) ?: __('We will be back soon'),
                'message' => trim((string) ($data['message'] ?? '')) ?: __('This website is undergoing maintenance. Please try again in a little while.'),
                'lang' => app()->getLocale() === 'nl' ? 'nl' : 'en',
            ]);
        } catch (AgentException $e) {
            return $this->failed($e);
        }
        $this->log($on ? 'maintenance_on' : 'maintenance_off', $install);

        return back()->with('status', $on
            ? __('Maintenance mode is on. Visitors see the maintenance page; you still see the website while logged in to WordPress.')
            : __('Maintenance mode is off. The website is open for visitors again.'));
    }

    public function debug(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $hours = config('wordpress-switches.debug_hours');
        $allowed = $this->area($request) === 'admin' && config('wordpress-switches.debug_forever_admin') ? [...$hours, 0] : $hours;
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'hours' => ['nullable', 'integer', Rule::in($allowed)],
        ]);
        $on = (bool) $data['enabled'];
        $h = $on ? (int) ($data['hours'] ?? $hours[0]) : null;
        try {
            $this->switches->set($install, 'debug', $on, ['hours' => $h ?: null]);
        } catch (AgentException $e) {
            return $this->failed($e);
        }
        $this->log($on ? 'debug_on' : 'debug_off', $install, $on ? ['hours' => $h] : []);

        return back()->with('status', ! $on ? __('Debug mode is off.')
            : ($h ? trans_choice('Debug mode is on and switches itself off in :count hour.|Debug mode is on and switches itself off in :count hours.', $h)
                : __('Debug mode is on until you switch it off.')));
    }

    public function indexing(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        try {
            $this->switches->set($install, 'indexing', $on);
        } catch (AgentException $e) {
            return $this->failed($e);
        }
        $this->log($on ? 'indexing_on' : 'indexing_off', $install);

        return back()->with('status', $on
            ? __('Search engines may index this website again.')
            : __('Search engines are asked not to index this website.'));
    }

    public function cache(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        try {
            $result = $this->switches->clearCache($install);
        } catch (AgentException $e) {
            return $this->failed($e);
        }
        $this->log('cache_clear', $install);
        $plugins = collect($result['steps'] ?? [])->firstWhere('step', 'plugins')['plugins'] ?? [];
        $failed = collect($result['steps'] ?? [])->where('ok', false)->pluck('step')->map(fn ($s) => match ($s) {
            'page_cache' => __('page cache'), 'object_cache' => __('object cache'),
            'plugins' => __('cache plugins'), default => __('expired transients'),
        })->all();
        $back = back()->with('status', $plugins
            ? __('All caches are empty, including :plugins.', ['plugins' => implode(', ', $plugins)])
            : __('All caches are empty.'));

        return $failed ? $back->withErrors(['agent' => __('Not every cache could be emptied: :steps', ['steps' => implode(', ', $failed)])]) : $back;
    }

    public function refresh(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        try {
            $this->switches->state($install, true);
        } catch (AgentException $e) {
            return $this->failed($e);
        }

        return back();
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'level' => ['nullable', Rule::in(['all', 'error', 'warning', 'notice', 'deprecated', 'other'])],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        return ['level' => $data['level'] ?? 'all', 'q' => trim((string) ($data['q'] ?? '')), 'limit' => 300];
    }

    public function logPage(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $area = $this->area($request);
        $filters = $this->filters($request);
        $errors = [];
        $log = null;
        $state = $this->switches->stored($install);
        try {
            $state = $this->switches->state($install);
            $log = $this->switches->log($install, $filters);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }

        return view('wordpress::switches.debug-log', [
            'install' => $install, 'area' => $area, 'state' => $state, 'log' => $log, 'filters' => $filters,
            'agentErrors' => $errors,
        ]);
    }

    /** The entries as JSON, for "refresh automatically" on the log page. */
    public function entries(Request $request, WordPressInstall $install): JsonResponse
    {
        $this->authorizeInstall($request, $install);
        try {
            $log = $this->switches->log($install, $this->filters($request));
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'html' => view('wordpress::switches.entries', ['log' => $log])->render(),
            'size' => $log['size'] ?? 0, 'modified' => $log['modified'] ?? null,
        ]);
    }

    public function clearLog(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $which = $request->validate(['which' => ['nullable', Rule::in(['panel', 'public'])]])['which'] ?? 'panel';
        try {
            $this->switches->clearLog($install, $which);
        } catch (AgentException $e) {
            return $this->failed($e);
        }
        $this->log($which === 'public' ? 'debug_log_public_deleted' : 'debug_log_cleared', $install);

        return back()->with('status', $which === 'public'
            ? __('The public debug.log has been deleted.')
            : __('The debug log is empty.'));
    }
}
