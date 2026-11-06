<?php

declare(strict_types=1);

namespace Rhis\WebApps\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WebApps\Models\WebApp;
use Rhis\WebApps\WebAppManager;

/** Admin: which Node.js and Python versions the server offers. */
final class RuntimeController extends Controller
{
    public function __construct(private readonly WebAppManager $apps, private readonly AgentClient $agent) {}

    public function index(): View
    {
        $usage = WebApp::query()->selectRaw('runtime, runtime_version, count(*) as n')->groupBy('runtime', 'runtime_version')->get()
            ->mapWithKeys(fn ($r) => [$r->runtime.':'.$r->runtime_version => (int) $r->n]);

        return view('webapps::admin.runtimes', [
            'runtimes' => $this->apps->runtimes(true), 'usage' => $usage, 'job' => Cache::get('webapps.runtime_job'),
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $job = $this->agent->call('runtime.install', $data, 30)['job'];
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        Cache::put('webapps.runtime_job', ['id' => $job] + $data, now()->addHour());
        ActivityLog::record('runtime.install', WebApp::RUNTIMES[$data['runtime']].' '.$data['version']);

        return back()->with('status', __(':runtime :version is being installed.', ['runtime' => WebApp::RUNTIMES[$data['runtime']], 'version' => $data['version']]));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        if (WebApp::where('runtime', $data['runtime'])->where('runtime_version', $data['version'])->exists()) {
            return back()->withErrors(['agent' => __('Apps still use this version. Move them to another version first.')]);
        }
        try {
            $this->agent->call('runtime.remove', $data, 300);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => __($e->getMessage())]);
        }
        Cache::forget('webapps.runtimes');
        ActivityLog::record('runtime.remove', WebApp::RUNTIMES[$data['runtime']].' '.$data['version']);

        return back()->with('status', __(':runtime :version removed.', ['runtime' => WebApp::RUNTIMES[$data['runtime']], 'version' => $data['version']]));
    }

    public function job(): JsonResponse
    {
        $job = Cache::get('webapps.runtime_job');
        if (! $job) {
            return response()->json(['status' => 'none']);
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $job['id']], 10);
        } catch (AgentException) {
            Cache::forget('webapps.runtime_job');

            return response()->json(['status' => 'none']);
        }
        if (($state['status'] ?? 'running') !== 'running') {
            Cache::forget('webapps.runtime_job');
            Cache::forget('webapps.runtimes');
        }

        return response()->json(['status' => $state['status'] ?? 'running', 'log' => array_slice($state['log'] ?? [], -30),
            'error' => $state['error']['message'] ?? null] + $job);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['runtime' => ['required', Rule::in(array_keys(WebApp::RUNTIMES))], 'version' => ['required', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$/']]);

        return ['runtime' => $data['runtime'], 'version' => $data['version']];
    }
}
