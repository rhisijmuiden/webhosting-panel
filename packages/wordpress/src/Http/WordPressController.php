<?php

declare(strict_types=1);

namespace Rhis\WordPress\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Toolkit;

/** The screens admins and customers share; the subclasses decide who may see which install. */
abstract class WordPressController extends Controller
{
    /** 'admin' or 'client': route names, layout and views. */
    protected string $area;

    public function __construct(protected readonly Toolkit $toolkit) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    /** The customer whose installs this user may see (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.wordpress.{$name}", $params);
    }

    public function index(Request $request): View
    {
        $filter = $request->query('filter') === 'outdated' ? 'outdated' : 'all';
        $installs = WordPressInstall::with('website.customer')
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))
            ->when($filter === 'outdated', fn ($q) => $q->outdated())
            ->get()->sortBy(fn ($i) => $i->label())->values();

        return view("wordpress::{$this->area}.index", [
            'installs' => $installs, 'filter' => $filter, 'area' => $this->area,
            'summary' => Toolkit::summary($this->customerId($request)),
            'jobs' => $this->toolkit->recentJobs($this->customerId($request), 5),
        ]);
    }

    public function show(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $errors = [];
        $admins = $snapshots = [];
        $cron = null;
        try {
            $admins = $this->toolkit->admins($install);
            $cron = $this->toolkit->cron($install);
            $snapshots = $this->toolkit->snapshots($install);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }

        return view("wordpress::{$this->area}.show", [
            'install' => $install, 'admins' => $admins, 'cron' => $cron, 'snapshots' => $snapshots,
            'agentErrors' => $errors, 'area' => $this->area,
        ]);
    }

    public function refresh(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $this->toolkit->refresh($install);

        return back()->with('status', __('Checked for updates.'));
    }

    public function update(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'selected'])],
            'core' => ['sometimes', 'boolean'],
            'plugins' => ['sometimes', 'array', 'max:200'],
            'plugins.*' => ['string', 'regex:/^[a-z0-9][a-z0-9._-]{0,99}$/'],
            'themes' => ['sometimes', 'array', 'max:200'],
            'themes.*' => ['string', 'regex:/^[a-z0-9][a-z0-9._-]{0,99}$/'],
            'snapshot' => ['sometimes', 'boolean'],
        ]);
        if ($data['scope'] === 'all') {
            [$core, $plugins, $themes] = [true, 'all', 'all'];
        } else {
            $core = (bool) ($data['core'] ?? false);
            $plugins = array_values($data['plugins'] ?? []) ?: false;
            $themes = array_values($data['themes'] ?? []) ?: false;
            if (! $core && ! $plugins && ! $themes) {
                return back()->withErrors(['agent' => __('Choose what to update.')]);
            }
        }
        // Customers always get a snapshot; admins may skip it for a quick update.
        $snapshot = $this->area === 'client' || (bool) ($data['snapshot'] ?? true);
        try {
            $job = $this->toolkit->update($install, $core, $plugins, $themes, $snapshot);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.update', $install->label(), ['scope' => $data['scope']], $install->website->customer_id);

        return redirect($this->route('jobs.show', $job));
    }

    public function updateOutdated(Request $request): RedirectResponse
    {
        $installs = WordPressInstall::with('website')->outdated()
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))->get();
        if ($installs->isEmpty()) {
            return back()->with('status', __('Everything is up to date.'));
        }
        try {
            $job = $this->toolkit->updateMany($installs, $this->customerId($request));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.update_many', (string) $installs->count(), ['installs' => $installs->map->label()->all()], $this->customerId($request));

        return redirect($this->route('jobs.show', $job));
    }

    public function cron(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['system', 'wordpress'])],
            'interval' => ['nullable', 'integer', Rule::in(config('wordpress.cron_intervals'))],
        ]);
        try {
            $this->toolkit->setCron($install, $data['mode'], (int) ($data['interval'] ?? 5));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.cron', $install->label(), $data, $install->website->customer_id);

        return back()->with('status', $data['mode'] === 'system'
            ? __('The server now runs WP-cron every :n minutes.', ['n' => (int) ($data['interval'] ?? 5)])
            : __('WordPress runs its own cron again.'));
    }

    public function password(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['generate', 'set'])],
            'password' => ['required_if:mode,set', 'nullable', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        try {
            $out = $this->toolkit->setPassword($install, (int) $data['user_id'], $data['mode'] === 'set' ? $data['password'] : null);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        $login = $out['user']['login'] ?? (string) $data['user_id'];
        ActivityLog::record('wordpress.password', $install->label(), ['user' => $login], $install->website->customer_id);

        $back = back()->with('status', __('The password of :user has been changed.', ['user' => $login]));

        // A generated password is shown once, right after this redirect.
        return isset($out['password']) ? $back->with('wp_password', ['user' => $login, 'password' => $out['password']]) : $back;
    }

    public function login(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate(['user_id' => ['nullable', 'integer', 'min:1']]);
        try {
            $url = $this->toolkit->loginLink($install, isset($data['user_id']) ? (int) $data['user_id'] : null);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.login', $install->label(), [], $install->website->customer_id);

        return redirect()->away($url);
    }

    public function restore(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate(['snapshot' => ['required', 'regex:/^\d{8}-\d{6}$/']]);
        try {
            $job = $this->toolkit->restore($install, $data['snapshot']);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.restore', $install->label(), $data, $install->website->customer_id);

        return redirect($this->route('jobs.show', $job));
    }

    public function job(Request $request, string $job): View
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $job), 404);
        $meta = $this->toolkit->job($job);
        abort_unless($meta && ($this->customerId($request) === null || $meta['customer_id'] === $this->customerId($request)), 404);
        $errors = [];
        $state = null;
        try {
            $state = $this->toolkit->follow($job);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        $installs = WordPressInstall::with('website')->whereIn('id', $meta['installs'])->get();

        return view("wordpress::{$this->area}.job", [
            'meta' => $meta, 'job' => $state, 'installs' => $installs, 'agentErrors' => $errors, 'area' => $this->area,
        ]);
    }
}
