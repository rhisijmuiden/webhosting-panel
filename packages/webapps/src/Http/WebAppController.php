<?php

declare(strict_types=1);

namespace Rhis\WebApps\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WebApps\Models\WebApp;
use Rhis\WebApps\WebAppManager;

/** The screens admins and customers share; the subclasses decide who may see which app. */
abstract class WebAppController extends Controller
{
    /** 'admin' or 'client': route names, layout and views. */
    protected string $area;

    /** First folder names an app may not use (Git, logs, PHP sessions ...). */
    private const RESERVED = ['git', 'logs', 'tmp', 'sessions', '.ssh', '.config', '.cache'];

    private const SEGMENT = '[A-Za-z0-9_.][A-Za-z0-9_.-]{0,99}';

    public function __construct(protected readonly WebAppManager $apps) {}

    /** The customer whose websites this user may use (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.webapps.{$name}", $params);
    }

    protected function authorizeApp(Request $request, WebApp $app): void
    {
        $id = $this->customerId($request);
        abort_unless($app->website && ($id === null || $app->website->customer_id === $id), 404);
    }

    /** Websites that may get an app (customers: only packages that allow apps). */
    protected function websites(Request $request): Collection
    {
        return Website::query()->where('status', '!=', 'removing')
            ->when($this->customerId($request), fn ($q, $id) => $q->where('customer_id', $id))
            ->orderBy('domain')->get()
            ->filter(fn (Website $w) => $this->area === 'admin' || WebAppManager::allowed($w))->values();
    }

    public function index(Request $request): View
    {
        $list = WebApp::with('website.customer')
            ->when($this->customerId($request), fn ($q, $id) => $q->forCustomer($id))
            ->get()->sortBy(fn (WebApp $a) => $a->website->domain.$a->mount_path)->values();

        return view("webapps::{$this->area}.index", [
            'list' => $list, 'statuses' => $this->apps->statuses($list), 'area' => $this->area,
            'runtimes' => $this->area === 'admin' ? $this->apps->runtimes() : null,
            'canCreate' => $this->websites($request)->isNotEmpty(),
        ]);
    }

    public function create(Request $request): View
    {
        $websites = $this->websites($request);
        abort_if($websites->isEmpty() && $this->area === 'client', 404);
        $website = $websites->firstWhere('id', (int) $request->query('website')) ?? $websites->first();

        return view("webapps::{$this->area}.create", [
            'websites' => $websites, 'website' => $website, 'area' => $this->area,
            'app' => new WebApp(['runtime' => 'node', 'start_command' => 'npm start', 'name' => '']),
            'choices' => $this->apps->choices(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $websites = $this->websites($request);
        $data = $request->validate(['website_id' => ['required', 'integer', Rule::in($websites->pluck('id'))]] + $this->rules($request));
        $website = $websites->firstWhere('id', (int) $data['website_id']);
        $max = WebAppManager::limit($website);
        if (WebApp::where('website_id', $website->id)->count() >= $max) {
            return back()->withInput()->withErrors(['name' => $max === 0
                ? __('The package of this website does not include Node.js and Python apps.')
                : __('This website already has the maximum number of apps (:max).', ['max' => $max])]);
        }
        if ($error = $this->checkSettings($website, $data)) {
            return back()->withInput()->withErrors($error);
        }
        $app = WebApp::create(['website_id' => $website->id, 'uid' => WebApp::newUid()] + $this->settings($request, $data));
        try {
            $this->apps->apply($app->setRelation('website', $website));
        } catch (AgentException $e) {
            $app->delete();

            return back()->withInput()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('webapp.created', $website->domain.$app->mount_path, ['runtime' => $app->runtimeLabel()], $website->customer_id);

        return redirect($this->route('show', $app))->with('webapp_new', true)
            ->with('status', __('App created. Install the dependencies once your code is in the app folder.'));
    }

    public function show(Request $request, WebApp $webapp): View
    {
        $this->authorizeApp($request, $webapp);
        $status = $this->apps->status($webapp);
        $logs = [];
        $agentErrors = [];
        try {
            $logs = $this->apps->logs($webapp, 100);
        } catch (AgentException $e) {
            $agentErrors[] = $e->getMessage();
        }
        if ($status === null) {
            $agentErrors[] = __('The server did not answer: the status is unknown.');
        }

        return view("webapps::{$this->area}.show", [
            'app' => $webapp->refresh()->load('website'), 'status' => $status, 'logs' => $logs, 'area' => $this->area,
            'agentErrors' => $agentErrors, 'choices' => $this->apps->choices($webapp), 'package' => $webapp->website->package,
        ]);
    }

    public function update(Request $request, WebApp $webapp): RedirectResponse
    {
        $this->authorizeApp($request, $webapp);
        $data = $this->validateSettings($request, $webapp);
        if ($error = $this->checkSettings($webapp->website, $data, $webapp)) {
            return back()->withInput()->withErrors($error);
        }
        $before = $webapp->getAttributes();
        $webapp->fill($this->settings($request, $data, $webapp))->save();
        try {
            $this->apps->apply($webapp);
        } catch (AgentException $e) {
            DB::table('web_apps')->where('id', $webapp->id)->update(array_intersect_key($before, array_flip(['runtime', 'runtime_version', 'app_root', 'mount_path', 'strip_prefix', 'start_command', 'build_command', 'env', 'deps_on_deploy', 'enabled'])) + ['last_error' => $e->getMessage()]);

            return back()->withInput()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('webapp.updated', $webapp->website->domain.$webapp->mount_path, [], $webapp->website->customer_id);

        return back()->with('status', __('Settings saved. The app was restarted.'));
    }

    public function control(Request $request, WebApp $webapp): RedirectResponse
    {
        $this->authorizeApp($request, $webapp);
        $op = $request->validate(['op' => ['required', Rule::in(['start', 'stop', 'restart'])]])['op'];
        try {
            $this->apps->control($webapp, $op);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record("webapp.$op", $webapp->website->domain.$webapp->mount_path, [], $webapp->website->customer_id);

        return back()->with('status', match ($op) {
            'start' => __('App started.'), 'stop' => __('App stopped. It stays off after a reboot until you start it.'), default => __('App restarted.'),
        });
    }

    public function install(Request $request, WebApp $webapp): RedirectResponse
    {
        $this->authorizeApp($request, $webapp);
        try {
            $this->apps->install($webapp);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('webapp.install', $webapp->website->domain.$webapp->mount_path, [], $webapp->website->customer_id);

        return back()->with('status', __('Installing dependencies. The app restarts when it is done.'));
    }

    /** JSON for the live log and the install progress on the app page. */
    public function poll(Request $request, WebApp $webapp): JsonResponse
    {
        $this->authorizeApp($request, $webapp);
        $out = ['status' => $this->apps->status($webapp), 'lines' => [], 'job' => null];
        try {
            $out['lines'] = $this->apps->logs($webapp, (int) min(1000, max(20, (int) $request->query('lines', 100))));
        } catch (AgentException) {
        }
        if ($webapp->install_job) {
            try {
                $job = $this->apps->job($webapp->install_job);
                $out['job'] = ['status' => $job['status'] ?? 'running', 'log' => array_slice($job['log'] ?? [], -60),
                    'error' => $job['error']['message'] ?? null];
                if (($job['status'] ?? 'running') !== 'running') {
                    $webapp->update(['install_job' => null, 'last_error' => ($job['status'] ?? '') === 'failed' ? ($job['error']['message'] ?? null) : null]);
                }
            } catch (AgentException) {
                $webapp->update(['install_job' => null]);
            }
        }

        return response()->json($out);
    }

    public function destroy(Request $request, WebApp $webapp): RedirectResponse
    {
        $this->authorizeApp($request, $webapp);
        $label = $webapp->website->domain.$webapp->mount_path;
        $customer = $webapp->website->customer_id;
        try {
            $this->apps->remove($webapp, $request->boolean('remove_deps'));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        ActivityLog::record('webapp.removed', $label, [], $customer);

        return redirect($this->route('index'))->with('status', __('App removed. The files in the app folder are kept.'));
    }

    // ------------------------------------------------------------- input

    protected function rules(Request $request): array
    {
        $seg = self::SEGMENT;

        return [
            'name' => ['required', 'string', 'max:80'],
            'runtime' => ['required', Rule::in(array_keys(WebApp::RUNTIMES))],
            'runtime_version' => ['required', 'string', 'max:10'],
            'app_root' => ['required', 'string', 'max:255', "regex:#^/?{$seg}(/{$seg}){0,7}/?$#"],
            'mount_path' => ['required', 'string', 'max:200', 'regex:#^/?([A-Za-z0-9._~-]{1,64}(/[A-Za-z0-9._~-]{1,64}){0,5})?/?$#'],
            'strip_prefix' => ['boolean'],
            'start_command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n\x00]/'],
            'build_command' => ['nullable', 'string', 'max:1000', 'not_regex:/[\r\n\x00]/'],
            'env' => ['array', 'max:100'],
            'env.*.key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'not_in:PORT,HOST,HOSTNAME'],
            'env.*.value' => ['nullable', 'string', 'max:8192', 'not_regex:/[\r\n\x00]/'],
            'deps_on_deploy' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    private function validateSettings(Request $request, WebApp $app): array
    {
        return $request->validate($this->rules($request), [], ['env.*.key' => __('variable name')]);
    }

    /** Checks that need the website: free path, reserved folders, installed runtime. */
    private function checkSettings(Website $website, array $data, ?WebApp $app = null): ?array
    {
        $root = trim($data['app_root'], '/');
        $mount = '/'.trim($data['mount_path'], '/');
        if (in_array(explode('/', $root)[0], self::RESERVED, true) || collect(explode('/', $root))->contains(fn ($s) => $s === '.' || $s === '..')) {
            return ['app_root' => __('This folder is used by the server. Choose another folder, for example app.')];
        }
        if (str_starts_with($mount, '/.well-known')) {
            return ['mount_path' => __('This path is used by the server.')];
        }
        $taken = WebApp::where('website_id', $website->id)->when($app, fn ($q) => $q->whereKeyNot($app->id))->get();
        if ($taken->contains(fn ($a) => $a->mount_path === $mount)) {
            return ['mount_path' => __('Another app already uses this address.')];
        }
        $choices = $this->apps->choices($app)[$data['runtime']] ?? [];
        if (! array_key_exists($data['runtime_version'], $choices)) {
            return ['runtime_version' => __('This version is not installed on the server.')];
        }

        return null;
    }

    private function settings(Request $request, array $data, ?WebApp $app = null): array
    {
        $env = [];
        foreach ($data['env'] ?? [] as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '') {
                $env[$key] = (string) ($row['value'] ?? '');
            }
        }

        return [
            'name' => trim($data['name']), 'runtime' => $data['runtime'], 'runtime_version' => $data['runtime_version'],
            'app_root' => trim($data['app_root'], '/'), 'mount_path' => '/'.trim($data['mount_path'], '/'),
            'strip_prefix' => $request->boolean('strip_prefix'), 'start_command' => trim($data['start_command']),
            'build_command' => filled($data['build_command'] ?? null) ? trim($data['build_command']) : null,
            'env' => $env, 'deps_on_deploy' => $request->boolean('deps_on_deploy'),
            'enabled' => $app ? $request->boolean('enabled', $app->enabled) : true,
        ];
    }
}
