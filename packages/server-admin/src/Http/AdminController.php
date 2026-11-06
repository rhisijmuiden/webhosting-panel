<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Http;

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
use Rhis\ServerAdmin\Maintenance;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Rhis\ServerAdmin\Options;
use Rhis\ServerAdmin\PhpLifecycle;
use Rhis\ServerAdmin\Runner;
use RuntimeException;

/** Server management: updates, reboot, maintenance window, scheduled tasks and PHP versions. */
class AdminController extends Controller
{
    private const UPDATES_CACHE = 'server-admin.updates';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Options $options,
        private readonly Runner $runner,
        private readonly Maintenance $maintenance,
    ) {}

    public function index(): View
    {
        try {
            $status = $this->agent->call('server.status', [], 30);
        } catch (AgentException $e) {
            $status = null;
            $error = $e->getMessage();
        }
        $updates = Cache::get(self::UPDATES_CACHE);
        if (! $updates && $status) {
            try {
                $updates = $this->agent->call('server.updates', [], 200);
                Cache::put(self::UPDATES_CACHE, $updates, now()->addHour());
            } catch (AgentException) {
                $updates = null;
            }
        }
        $scan = $this->options->state('last_scan');
        $scores = [];
        foreach ($scan['packages'] ?? [] as $p) {
            $scores[$p['name']] = $p;
        }

        return view('server-admin::admin.index', [
            'status' => $status,
            'agentError' => $error ?? null,
            'updates' => $updates,
            'scan' => $scan,
            'scores' => $scores,
            'session' => $this->maintenance->current(),
            'upgrade' => ServerTaskRun::running()->where('task', 'upgrade')->latest('id')->first(),
            'runs' => ServerTaskRun::latest('id')->limit(15)->get(),
            'hostname' => $this->hostname(),
            'nextWindow' => $this->options->nextWindow(),
            'o' => $this->options,
        ]);
    }

    public function refresh(): RedirectResponse
    {
        try {
            Cache::put(self::UPDATES_CACHE, $this->agent->call('server.updates', ['refresh' => true], 400), now()->addHour());
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', __('The list of updates is refreshed.'));
    }

    public function upgrade(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['security', 'all'])]]);
        if ($this->maintenance->current() || ServerTaskRun::running()->whereIn('task', ['upgrade', 'php_install', 'php_remove'])->exists()) {
            return back()->withErrors(['agent' => __('Something is already being installed. Wait until it is done.')]);
        }
        Cache::forget(self::UPDATES_CACHE);
        ActivityLog::record('server.upgrade_started', $data['mode']);
        $run = $this->runner->startAgent('upgrade', 'server.upgrade', ['mode' => $data['mode']], 'manual', $request->user()->id);

        return redirect()->route('admin.server.run', $run);
    }

    public function reboot(Request $request): RedirectResponse
    {
        $hostname = $this->hostname();
        $request->validate(['confirm' => ['required', 'string', function ($attr, $value, $fail) use ($hostname) {
            if (strtolower(trim((string) $value)) !== strtolower($hostname)) {
                $fail(__('Type :name to confirm.', ['name' => $hostname]));
            }
        }]]);
        try {
            $run = $this->maintenance->manualReboot($request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return redirect()->route('admin.server.run', $run)
            ->with('status', __('Every website is checked first, then the server reboots. Afterwards the websites are checked again.'));
    }

    public function cancel(Request $request, ServerTaskRun $run): RedirectResponse
    {
        abort_unless($run->task === 'maintenance' && $run->isRunning(), 404);
        try {
            $this->maintenance->cancel($run, $request->user()->id);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', __('Cancelled.'));
    }

    public function run(ServerTaskRun $run): View
    {
        return view('server-admin::admin.run', ['run' => $run, 'o' => $this->options]);
    }

    public function runJson(ServerTaskRun $run): JsonResponse
    {
        if ($run->isRunning() && $run->agent_job) {
            $run = $this->runner->poll($run);
        }
        $state = $run->result ?? [];

        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'phase' => $run->task === 'maintenance' ? ($state['phase'] ?? null) : null,
            'summary' => $run->summary,
            'log' => (string) $run->log,
            'finished' => ! $run->isRunning(),
        ]);
    }

    public function maintenancePage(): View
    {
        return view('server-admin::admin.maintenance', [
            'o' => $this->options,
            'nextWindow' => $this->options->nextWindow(),
            'session' => $this->maintenance->current(),
            'sessions' => ServerTaskRun::where('task', 'maintenance')->latest('id')->limit(10)->get(),
            'hasNvdKey' => (bool) $this->options->get('nvd_api_key'),
        ]);
    }

    public function saveMaintenance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'window_enabled' => ['boolean'],
            'window_days' => ['array'],
            'window_days.*' => ['integer', 'between:0,6'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'different:window_start'],
            'window_install' => ['required', Rule::in(['security', 'all'])],
            'window_reboot' => ['boolean'],
            'mail_mode' => ['required', Rule::in(['always', 'reboots', 'problems'])],
            'mail_to' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $mail) {
                    if (! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                        $fail(__(':mail is not an email address', ['mail' => $mail]));
                    }
                }
            }],
            'critical_enabled' => ['boolean'],
            'critical_threshold' => ['required', 'numeric', 'between:4,10'],
            'critical_reboot' => ['required', Rule::in(['soon', 'window'])],
            'critical_reboot_delay' => ['required', 'integer', 'between:1,240'],
            'nvd_api_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'forget_nvd_key' => ['nullable', 'boolean'],
            'php_eol_enabled' => ['boolean'],
            'php_notice_days' => ['required', 'integer', 'between:0,365'],
            'php_grace_days' => ['required', 'integer', 'between:0,365'],
            'php_remove_old' => ['boolean'],
            'php_notify_customers' => ['boolean'],
        ]);
        $data['window_days'] = array_values(array_unique(array_map('intval', $data['window_days'] ?? [])));
        foreach (Options::KEYS as $key) {
            if ($key === 'nvd_api_key') {
                continue;
            }
            if (in_array($key, ['window_enabled', 'window_reboot', 'critical_enabled', 'php_eol_enabled', 'php_remove_old', 'php_notify_customers'], true)) {
                $this->options->set($key, (bool) ($data[$key] ?? false));
            } elseif (array_key_exists($key, $data)) {
                $this->options->set($key, $data[$key] ?? '');
            }
        }
        if (! empty($data['nvd_api_key'])) {
            $this->options->set('nvd_api_key', $data['nvd_api_key']);
        } elseif (! empty($data['forget_nvd_key'])) {
            app(\App\Services\Settings::class)->forget('server.nvd_api_key');
        }
        ActivityLog::record('server.maintenance_saved');

        try {
            $mode = $this->options->get('window_enabled') ? 'window' : 'daily';
            $this->agent->call('server.auto_updates', ['mode' => $mode], 30);
            $this->options->setState('auto_updates', $mode);
        } catch (AgentException $e) {
            $this->options->setState('auto_updates', null); // the scheduler tries again
            return back()->with('warning', __('Saved, but the server could not be told yet: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('status', __('Maintenance settings saved.'));
    }

    public function tasks(): View
    {
        $tasks = ServerTask::synced();

        return view('server-admin::admin.tasks', [
            'tasks' => $tasks,
            'last' => $tasks->mapWithKeys(fn ($t) => [$t->key => $t->lastRun()]),
            'runs' => ServerTaskRun::whereIn('task', $tasks->keys())->latest('id')->limit(20)->get(),
        ]);
    }

    public function saveTask(Request $request, string $key): RedirectResponse
    {
        $task = ServerTask::synced()->get($key) ?? abort(404);
        $data = $request->validate([
            'enabled' => ['boolean'],
            'frequency' => ['required', Rule::in(ServerTask::FREQUENCIES)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'day' => ['nullable', 'integer', 'between:1,28'],
            'options' => ['array'],
            'options.*' => ['nullable', 'integer', 'between:0,3650'],
        ]);
        $defaults = (array) config("server-admin.tasks.$key.options", []);
        $options = [];
        foreach ($defaults as $k => $default) {
            $options[$k] = is_bool($default) ? (bool) ($data['options'][$k] ?? false) : (int) ($data['options'][$k] ?? $default);
        }
        $task->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'frequency' => $data['frequency'],
            'time' => $data['time'],
            'weekday' => $data['weekday'] ?? $task->weekday,
            'day' => $data['day'] ?? $task->day,
            'options' => $options,
        ]);
        $task->next_run_at = $task->nextRun();
        $task->save();
        ActivityLog::record('server.task_saved', $key);

        return back()->with('status', __('Task saved.'));
    }

    public function runTask(Request $request, string $key): RedirectResponse
    {
        $task = ServerTask::synced()->get($key) ?? abort(404);
        if (ServerTaskRun::running()->where('task', $key)->exists()) {
            return back()->withErrors(['agent' => __('This task is already running.')]);
        }
        ActivityLog::record('server.task_run', $key);
        $run = $this->runner->runTask($task, 'manual', $request->user()->id);

        return redirect()->route('admin.server.run', $run);
    }

    public function php(PhpLifecycle $php): View
    {
        $facts = $php->facts();

        return view('server-admin::admin.php', [
            'rows' => $php->overview($facts),
            'facts' => $facts,
            'failed' => (array) $this->options->state('php_failed', []),
            'runs' => ServerTaskRun::whereIn('task', ['php_eol', 'php_install', 'php_remove'])->latest('id')->limit(10)->get(),
            'o' => $this->options,
        ]);
    }

    public function phpInstall(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^\d\.\d$/']]);
        if (ServerTaskRun::running()->whereIn('task', ['upgrade', 'php_install', 'php_remove'])->exists() || $this->maintenance->current()) {
            return back()->withErrors(['agent' => __('Something is already being installed. Wait until it is done.')]);
        }
        ActivityLog::record('server.php_install', $data['version']);
        $run = $this->runner->startAgent('php_install', 'server.php_install', ['version' => $data['version']], 'manual', $request->user()->id);

        return redirect()->route('admin.server.run', $run);
    }

    public function phpRemove(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^\d\.\d$/']]);
        if (\App\Models\Website::where('php_version', $data['version'])->exists()) {
            return back()->withErrors(['agent' => __('Websites still use PHP :v. Move them first.', ['v' => $data['version']])]);
        }
        if (ServerTaskRun::running()->whereIn('task', ['upgrade', 'php_install', 'php_remove'])->exists() || $this->maintenance->current()) {
            return back()->withErrors(['agent' => __('Something is already being installed. Wait until it is done.')]);
        }
        ActivityLog::record('server.php_remove', $data['version']);
        $run = $this->runner->startAgent('php_remove', 'server.php_remove', ['version' => $data['version']], 'manual', $request->user()->id);

        return redirect()->route('admin.server.run', $run);
    }

    /** Move the websites of one version now (also before its end of life). */
    public function phpMigrate(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^\d\.\d$/']]);
        if (ServerTaskRun::running()->where('task', 'php_eol')->exists()) {
            return back()->withErrors(['agent' => __('This task is already running.')]);
        }
        ActivityLog::record('server.php_migrate', $data['version']);
        $run = $this->runner->queueLocal('php_eol', 'manual', $request->user()->id, $data['version']);

        return redirect()->route('admin.server.run', $run);
    }

    public function phpRetry(Request $request): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $failed = (array) $this->options->state('php_failed', []);
        unset($failed[$data['domain']]);
        $this->options->setState('php_failed', $failed ?: null);

        return back()->with('status', __(':domain is tried again on the next run.', ['domain' => $data['domain']]));
    }

    private function hostname(): string
    {
        return (string) (gethostname() ?: 'server');
    }
}
