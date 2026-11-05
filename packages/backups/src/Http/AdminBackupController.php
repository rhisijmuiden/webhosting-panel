<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Backups\BackupJobs;

final class AdminBackupController extends Controller
{
    public const TYPES = ['sftp', 'scp', 'ftps', 'ftp', 'onedrive', 'local'];

    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private const TARGET = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    private const SCOPE = '/^(all|system|sites|mail|databases|(site|mail):[a-z0-9.-]{1,253}|db:[A-Za-z0-9_]{1,64})$/';

    public function __construct(private readonly AgentClient $agent) {}

    public function index(Request $request): View
    {
        $errors = [];
        $status = $this->safe(fn () => $this->agent->call('backup.status'), $errors, ['targets' => [], 'last_run' => null, 'running' => false]);
        $schedule = $this->safe(fn () => $this->agent->call('backup.get_schedule'), $errors, [
            'incremental' => ['enabled' => true, 'time' => '02:30'], 'full' => ['enabled' => true, 'day' => 'Sun', 'time' => '03:30'],
        ]);
        $targets = $status['targets'] ?? [];
        $selected = $request->query('target');
        if (! is_string($selected) || ! collect($targets)->contains('name', $selected)) {
            $selected = $targets[0]['name'] ?? null;
        }
        $scope = $request->query('scope');
        $scope = is_string($scope) && preg_match(self::SCOPE, $scope) && $scope !== 'all' ? $scope : null;

        $snapshots = [];
        if ($selected) {
            $snapshots = $this->safe(
                fn () => $this->agent->call('backup.list', array_filter(['target' => $selected, 'scope' => $scope]), 180)['snapshots'] ?? [],
                $errors, [],
            );
        }

        return view('backups::admin.index', [
            'status' => $status, 'schedule' => $schedule, 'targets' => $targets, 'selected' => $selected,
            'scope' => $scope, 'snapshots' => $snapshots, 'jobs' => BackupJobs::recent(), 'agentErrors' => $errors,
            'types' => self::TYPES, 'days' => self::DAYS,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['incremental', 'full'])],
            'target' => ['nullable', 'regex:'.self::TARGET],
            'scope' => ['nullable', 'regex:'.self::SCOPE],
        ]);
        $params = ['full' => $data['kind'] === 'full', 'prune' => true];
        if (! empty($data['target'])) {
            $params['target'] = $data['target'];
        }
        if (! empty($data['scope']) && $data['scope'] !== 'all') {
            $params['scopes'] = [$data['scope']];
        }

        return $this->startJob('backup.run', $params, $data['kind'] === 'full' ? __('Full backup') : __('Incremental backup'), 'backup.started');
    }

    public function restore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'regex:'.self::TARGET],
            'snapshot' => ['required', 'regex:/^([0-9a-f]{8}|[0-9a-f]{64})$/'],
            'scope' => ['required', 'regex:'.self::SCOPE],
            'mode' => ['required', Rule::in(['in_place', 'copy'])],
            'include' => ['nullable', 'string', 'max:1000', 'starts_with:/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'database' => ['nullable', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
        ]);
        $params = ['target' => $data['target'], 'snapshot' => $data['snapshot'], 'mode' => $data['mode']];
        if ($data['mode'] === 'copy') {
            $params['to'] = '/root/restore/'.substr($data['snapshot'], 0, 8);
        }
        if (! empty($data['include'])) {
            $params['include'] = $data['include'];
        }
        if (! empty($data['database'])) {
            $params['database'] = $data['database'];
        }

        return $this->startJob('backup.restore', $params, __('Restore :scope', ['scope' => $data['scope']]), 'backup.restored');
    }

    public function schedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'incremental_enabled' => ['boolean'],
            'incremental_time' => ['required', 'date_format:H:i'],
            'full_enabled' => ['boolean'],
            'full_day' => ['required', Rule::in(self::DAYS)],
            'full_time' => ['required', 'date_format:H:i'],
        ]);
        $data['incremental_enabled'] = $request->boolean('incremental_enabled');
        $data['full_enabled'] = $request->boolean('full_enabled');

        try {
            $this->agent->call('backup.schedule', $data, 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('backup.schedule_changed', null, $data);

        return back()->with('status', __('Backup schedule saved.'));
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:'.self::TARGET],
            'type' => ['required', Rule::in(self::TYPES)],
            'path' => ['required', 'string', 'max:500', 'not_regex:/["\n]/'],
            'host' => ['required_unless:type,onedrive,local', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'user' => ['required_unless:type,onedrive,local', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._@+-]+$/'],
            'password' => ['nullable', 'string', 'max:1000'],
            'ssh_key' => ['nullable', 'string', 'max:16000'],
            'tls' => ['nullable', Rule::in(['explicit', 'implicit'])],
            'allow_plain_ftp' => ['boolean'],
            'onedrive_token' => ['required_if:type,onedrive', 'nullable', 'json', 'max:16000'],
            'drive_type' => ['nullable', Rule::in(['personal', 'business', 'documentLibrary'])],
            'drive_id' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9!._-]+$/'],
            'repo_key' => ['nullable', 'string', 'max:500'],
        ]);
        if (in_array($data['type'], ['sftp', 'scp'], true) && empty($data['password']) && empty($data['ssh_key'])) {
            return back()->withInput($request->except(['password', 'ssh_key', 'repo_key', 'onedrive_token']))
                ->withErrors(['ssh_key' => __('Give an SSH key or a password.')]);
        }
        if (in_array($data['type'], ['ftp', 'ftps'], true) && empty($data['password'])) {
            return back()->withInput($request->except(['password', 'ssh_key', 'repo_key', 'onedrive_token']))
                ->withErrors(['password' => __('A password is required for FTP.')]);
        }
        $data['allow_plain_ftp'] = $request->boolean('allow_plain_ftp');
        $params = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (isset($params['port'])) {
            $params['port'] = (int) $params['port'];
        }

        try {
            $target = $this->agent->call('backup.target_add', $params, 330);
        } catch (AgentException $e) {
            return back()->withInput($request->except(['password', 'ssh_key', 'repo_key', 'onedrive_token']))
                ->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('backup.target_added', $data['name'], ['type' => $data['type'], 'host' => $data['host'] ?? null]);

        $redirect = redirect()->route('admin.backups.index', ['target' => $data['name']])
            ->with('status', __('Backup location :name added.', ['name' => $data['name']]));
        if (empty($data['repo_key'])) {
            // A new repository: show the key once, it is needed to restore on another server.
            $key = $this->safe(fn () => $this->agent->call('backup.target_export_key', ['target' => $data['name']]), $ignored, []);
            $redirect->with('repository_key', ['target' => $data['name'], 'key' => $key['repository_key'] ?? null]);
        }

        return $redirect;
    }

    public function testTarget(string $target): RedirectResponse
    {
        $this->assertTarget($target);
        try {
            $result = $this->agent->call('backup.target_test', ['target' => $target], 150);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        if (! ($result['reachable'] ?? false)) {
            return back()->withErrors(['agent' => __('Cannot reach :name: :message', ['name' => $target, 'message' => $result['message'] ?? ''])]);
        }

        return back()->with('status', __(':name is reachable (:ms ms).', ['name' => $target, 'ms' => $result['duration_ms'] ?? '?']));
    }

    public function checkTarget(string $target): RedirectResponse
    {
        $this->assertTarget($target);

        return $this->startJob('backup.check', ['target' => $target, 'read_data' => true], __('Integrity check :name', ['name' => $target]), 'backup.checked');
    }

    public function exportKey(string $target): RedirectResponse
    {
        $this->assertTarget($target);
        try {
            $key = $this->agent->call('backup.target_export_key', ['target' => $target]);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('backup.key_viewed', $target);

        return back()->with('repository_key', ['target' => $target, 'key' => $key['repository_key'] ?? null]);
    }

    public function destroyTarget(string $target): RedirectResponse
    {
        $this->assertTarget($target);
        try {
            $this->agent->call('backup.target_remove', ['target' => $target]);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('backup.target_removed', $target);

        return redirect()->route('admin.backups.index')->with('status', __('Backup location :name removed. The backups on it were not deleted.', ['name' => $target]));
    }

    public function job(string $job): View
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $job), 404);
        $meta = BackupJobs::find($job);
        $errors = [];
        $state = $this->safe(fn () => $this->agent->call('job.status', ['job' => $job], 10), $errors, null);

        return view('backups::admin.job', ['job' => $state, 'meta' => $meta, 'agentErrors' => $errors]);
    }

    private function startJob(string $action, array $params, string $label, string $log): RedirectResponse
    {
        try {
            $result = $this->agent->call($action, $params, 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        BackupJobs::remember($result['job'], $label);
        ActivityLog::record($log, $params['target'] ?? null, $params);

        return redirect()->route('admin.backups.jobs.show', $result['job']);
    }

    private function assertTarget(string $target): void
    {
        abort_unless(preg_match(self::TARGET, $target), 404);
    }

    private function safe(\Closure $call, ?array &$errors, mixed $default): mixed
    {
        try {
            return $call();
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();

            return $default;
        }
    }
}
