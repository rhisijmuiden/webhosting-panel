<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
use Rhis\WordPress\SmartUpdates\Models\UpdateRun;
use Rhis\WordPress\SmartUpdates\Options;
use Rhis\WordPress\SmartUpdates\SmartUpdater;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Smart updates screens shared by admins and customers; the subclasses decide who sees what. */
abstract class SmartUpdatesController extends Controller
{
    /** 'admin' or 'client'. */
    protected string $area;

    public function __construct(protected readonly SmartUpdater $updater, protected readonly Options $options) {}

    /** The customer whose installs this user may see (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        $id = $this->customerId($request);
        abort_unless($id === null || $install->website?->customer_id === $id, 404);
    }

    protected function mayChange(): bool
    {
        return true;
    }

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.wordpress.smart.{$name}", $params);
    }

    protected function view(string $name, array $data): View
    {
        return view("wordpress::smart-updates.{$this->area}.{$name}", $data + [
            'area' => $this->area, 'mayChange' => $this->mayChange(), 'updater' => $this->updater, 'options' => $this->options,
        ]);
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['history', 'settings'], true) ? $request->query('tab') : 'sites';
        if ($tab === 'settings' && $this->area !== 'admin') {
            $tab = 'sites';
        }
        $customerId = $this->customerId($request);
        $installs = WordPressInstall::with('website.customer')
            ->when($customerId, fn ($q, $id) => $q->forCustomer($id))
            ->get()->sortBy(fn ($i) => $i->label())->values();
        $policies = UpdatePolicy::whereIn('wordpress_install_id', $installs->pluck('id'))->get()->keyBy('wordpress_install_id');
        $last = UpdateRun::whereIn('wordpress_install_id', $installs->pluck('id'))
            ->whereIn('id', UpdateRun::selectRaw('max(id)')->groupBy('wordpress_install_id'))
            ->get()->keyBy('wordpress_install_id');
        $filter = in_array($request->query('mode'), UpdatePolicy::MODES, true) ? $request->query('mode') : null;
        if ($filter) {
            $installs = $installs->filter(fn ($i) => ($policies[$i->id] ?? new UpdatePolicy)->effectiveMode() === $filter)->values();
        }
        $history = $tab === 'history'
            ? UpdateRun::with('install.website.customer')->when($customerId, fn ($q, $id) => $q->forCustomer($id))
                ->when(in_array($request->query('status'), ['updated', 'rolled_back', 'failed', 'notified', 'running'], true),
                    fn ($q) => $q->where('status', $request->query('status')))
                ->latest('id')->paginate(25)->withQueryString()
            : null;

        return $this->view('index', [
            'tab' => $tab, 'installs' => $installs, 'policies' => $policies, 'last' => $last, 'filter' => $filter,
            'history' => $history, 'summary' => $this->updater->overview($customerId),
            'capabilities' => $tab === 'settings' ? $this->capabilities() : null,
        ]);
    }

    public function install(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);

        return $this->view('install', [
            'install' => $install, 'policy' => UpdatePolicy::for($install),
            'runs' => UpdateRun::where('wordpress_install_id', $install->id)->latest('id')->limit(20)->get(),
            'security' => $this->updater->securityTargets($install),
            'summary' => $this->updater->overview($this->customerId($request)),
        ]);
    }

    public function save(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        abort_unless($this->mayChange(), 403);
        $data = $request->validate([
            'mode' => ['required', Rule::in([...UpdatePolicy::MODES, 'default'])],
            'visual_check' => ['sometimes', 'boolean'],
            'threshold' => ['nullable', 'integer', 'min:1', 'max:90'],
            'pages' => ['nullable', 'string', 'max:1200'],
            'mail' => ['required', Rule::in(UpdatePolicy::MAILS)],
        ]);
        $pages = collect(preg_split('/\r?\n|,/', (string) ($data['pages'] ?? '')))->map(fn ($p) => trim($p))->filter()
            ->map(fn ($p) => preg_replace('#^https?://[^/]+#i', '', $p) ?: '/')
            ->map(fn ($p) => str_starts_with($p, '/') ? $p : '/'.$p)->unique()->reject(fn ($p) => $p === '/')->values();
        foreach ($pages as $p) {
            if (! preg_match('#^/[A-Za-z0-9._~%/-]{0,200}$#', $p) || str_contains($p, '..')) {
                return back()->withInput()->withErrors(['pages' => __('":page" is not a valid page address. Use paths like /contact.', ['page' => $p])]);
            }
        }
        if ($pages->count() > UpdatePolicy::MAX_PAGES) {
            return back()->withInput()->withErrors(['pages' => __('At most :n extra pages.', ['n' => UpdatePolicy::MAX_PAGES])]);
        }
        $policy = UpdatePolicy::for($install);
        $policy->fill([
            'mode' => $data['mode'] === 'default' ? null : $data['mode'],
            'visual_check' => (bool) ($data['visual_check'] ?? false),
            'threshold' => $data['threshold'] ?? null,
            'pages' => $pages->all() ?: null,
            'mail' => $data['mail'],
        ])->save();
        ActivityLog::record('wordpress.smart_policy', $install->label(), ['mode' => $policy->effectiveMode()], $install->website->customer_id);

        return redirect($this->route('install', $install))->with('status', __('Settings saved.'));
    }

    public function bulk(Request $request): RedirectResponse
    {
        abort_unless($this->mayChange(), 403);
        $data = $request->validate([
            'installs' => ['required', 'array', 'max:1000'], 'installs.*' => ['integer'],
            'mode' => ['required', Rule::in([...UpdatePolicy::MODES, 'default'])],
        ]);
        $installs = WordPressInstall::with('website')->whereIn('id', $data['installs'])
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))->get();
        foreach ($installs as $install) {
            UpdatePolicy::for($install)->fill(['mode' => $data['mode'] === 'default' ? null : $data['mode']])->save();
        }
        ActivityLog::record('wordpress.smart_policy', (string) $installs->count(), ['mode' => $data['mode'], 'installs' => $installs->map->label()->all()], $this->customerId($request));

        return back()->with('status', trans_choice(':count site changed.|:count sites changed.', $installs->count()));
    }

    public function run(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate(['mode' => ['nullable', Rule::in(['all', 'security'])]]);
        try {
            $run = $this->updater->start($install, 'manual', null, $data['mode'] ?? 'all');
        } catch (RuntimeException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.smart_update_start', $install->label(), ['mode' => $run->mode], $install->website->customer_id);

        return redirect($this->route('runs.show', $run));
    }

    public function show(Request $request, UpdateRun $run): View
    {
        $run->load('install.website.customer');
        abort_unless($run->install, 404);
        $this->authorizeInstall($request, $run->install);
        if ($run->status === 'running') {
            $run = $this->updater->follow($run)->refresh();
        }

        return $this->view('run', ['run' => $run, 'install' => $run->install, 'policy' => UpdatePolicy::for($run->install)]);
    }

    public function status(Request $request, UpdateRun $run): JsonResponse
    {
        $run->load('install.website');
        abort_unless($run->install, 404);
        $this->authorizeInstall($request, $run->install);
        if ($run->status === 'running') {
            $run = $this->updater->follow($run)->refresh();
        }

        return response()->json(['status' => $run->status]);
    }

    public function image(Request $request, UpdateRun $run, string $name): Response
    {
        $run->load('install.website');
        abort_unless($run->install, 404);
        $this->authorizeInstall($request, $run->install);
        abort_unless($run->hasImage($name), 404);

        return response(Storage::disk('local')->get($run->imagePath($name)), 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** What the server can do (only asked on the settings tab). */
    protected function capabilities(): ?array
    {
        try {
            return app(AgentClient::class)->call('wp.smart_check', [], 15);
        } catch (AgentException) {
            return null;
        }
    }
}
