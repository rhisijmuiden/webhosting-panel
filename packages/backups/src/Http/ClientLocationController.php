<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;

/** The customer's own backup locations: set up, schedule, list backups on it, restore. */
final class ClientLocationController extends Controller
{
    private const SECRETS = ['password', 'ssh_key'];

    public function __construct(private readonly AgentClient $agent, private readonly ClientBackups $backups) {}

    public function create(Request $request): View
    {
        return view('backups::client.locations.form', ['target' => new ClientBackupTarget([
            'type' => 'sftp', 'schedule' => 'daily', 'schedule_time' => '03:00', 'full_day' => 'Sun', 'keep_full' => 4,
            'parts' => ClientBackupTarget::PARTS, 'tls' => 'explicit',
        ]), 'websites' => $this->customer($request)->websites()->orderBy('domain')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        abort_if(ClientBackupTarget::where('customer_id', $customer->id)->count() >= (int) config('backups.client_max_targets', 5), 403,
            __('You cannot add more backup locations.'));
        $target = new ClientBackupTarget(['customer_id' => $customer->id]);

        return $this->save($request, $target, true);
    }

    public function edit(Request $request, int $target): View
    {
        return view('backups::client.locations.form', [
            'target' => $this->find($request, $target), 'websites' => $this->customer($request)->websites()->orderBy('domain')->get(),
        ]);
    }

    public function update(Request $request, int $target): RedirectResponse
    {
        return $this->save($request, $this->find($request, $target), false);
    }

    public function destroy(Request $request, int $target): RedirectResponse
    {
        $t = $this->find($request, $target);
        $t->delete();
        ActivityLog::record('client_backup.location_removed', $t->name, [], $t->customer_id);

        return redirect()->route('client.backups.index')->with('status', __('Backup location :name removed. The backups on it were not deleted.', ['name' => $t->name]));
    }

    public function test(Request $request, int $target): RedirectResponse
    {
        $t = $this->find($request, $target);
        $error = $this->probe($t, $request);

        return $error
            ? back()->withErrors(['agent' => __('Cannot reach :name: :message', ['name' => $t->name, 'message' => $error])])
            : back()->with('status', __(':name is reachable and writable.', ['name' => $t->name]));
    }

    /** Backups of one website on this location. */
    public function show(Request $request, int $target): View
    {
        $t = $this->find($request, $target);
        $websites = $this->customer($request)->websites()->orderBy('domain')->get();
        $website = $websites->firstWhere('id', (int) $request->query('website')) ?? $websites->first();
        $backups = [];
        $error = null;
        if ($website) {
            try {
                $backups = $this->agent->call('client_backup.list', ['domain' => $website->domain, 'target' => $t->agentTarget()], 120)['backups'] ?? [];
            } catch (AgentException $e) {
                $error = $e->getMessage();
            }
        }

        return view('backups::client.locations.show', ['target' => $t, 'websites' => $websites, 'website' => $website,
            'backups' => $backups, 'error' => $error]);
    }

    public function restore(Request $request, int $target): RedirectResponse
    {
        $t = $this->find($request, $target);
        $data = $request->validate([
            'website' => ['required', 'integer'],
            'file' => ['required', 'string', 'max:400', 'regex:/^[a-z0-9.-]+-\d{8}-\d{6}-(full|incr-\d{8}-\d{6})\.zip$/'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(ClientBackupTarget::PARTS)],
            'safety' => ['boolean'],
        ]);
        $website = Website::where('customer_id', $t->customer_id)->findOrFail($data['website']);
        abort_unless(str_starts_with($data['file'], $website->domain.'-'), 404);
        if (ClientBackup::where('website_id', $website->id)->where('status', 'running')->exists()) {
            return back()->withErrors(['agent' => __('A backup or restore of this website is already running.')]);
        }
        try {
            $record = $this->backups->restore($t, $website, $data['file'], array_values($data['parts']), $request->boolean('safety'));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return redirect()->route('client.backups.jobs.show', $record->job_id);
    }

    public function destroyFile(Request $request, int $target): RedirectResponse
    {
        $t = $this->find($request, $target);
        $data = $request->validate([
            'website' => ['required', 'integer'],
            'file' => ['required', 'string', 'max:400', 'regex:/^[a-z0-9.-]+-\d{8}-\d{6}-(full|incr-\d{8}-\d{6})\.zip$/'],
        ]);
        $website = Website::where('customer_id', $t->customer_id)->findOrFail($data['website']);
        abort_unless(str_starts_with($data['file'], $website->domain.'-'), 404);
        try {
            $this->agent->call('client_backup.delete', ['domain' => $website->domain, 'target' => $t->agentTarget(), 'file' => $data['file']], 120);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('client_backup.deleted', $data['file'], ['location' => $t->name], $t->customer_id);

        return back()->with('status', __('Backup :file deleted.', ['file' => $data['file']]));
    }

    private function save(Request $request, ClientBackupTarget $target, bool $new): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(ClientBackupTarget::TYPES)],
            'host' => ['required_unless:type,onedrive', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_unless:type,onedrive', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._@+-]+$/'],
            'password' => ['nullable', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'ssh_key' => ['nullable', 'string', 'max:16000'],
            'tls' => ['nullable', Rule::in(['explicit', 'implicit'])],
            'path' => ['nullable', 'string', 'max:300', 'regex:/^[A-Za-z0-9._ ~@+\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'allow_plain_ftp' => ['boolean'],
            'schedule' => ['required', Rule::in(ClientBackupTarget::SCHEDULES)],
            'schedule_time' => ['required', 'date_format:H:i'],
            'full_day' => ['required', Rule::in(ClientBackups::DAYS)],
            'keep_full' => ['required', 'integer', 'min:1', 'max:30'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(ClientBackupTarget::PARTS)],
            'websites' => ['nullable', 'array'],
            'websites.*' => ['integer'],
        ]);
        $withInput = fn () => back()->withInput($request->except(self::SECRETS));
        $type = $data['type'];
        $hasPassword = filled($data['password'] ?? null) || (! $new && filled($target->password) && $target->type === $type);
        $hasKey = filled($data['ssh_key'] ?? null) || (! $new && filled($target->ssh_key));
        if (in_array($type, ['ftp', 'ftps'], true) && ! $hasPassword) {
            return $withInput()->withErrors(['password' => __('A password is required for FTP.')]);
        }
        if (in_array($type, ['sftp', 'scp'], true) && ! $hasPassword && ! $hasKey) {
            return $withInput()->withErrors(['ssh_key' => __('Give an SSH key or a password.')]);
        }
        if ($type === 'ftp' && ! $request->boolean('allow_plain_ftp')) {
            return $withInput()->withErrors(['allow_plain_ftp' => __('Plain FTP sends the password unencrypted. Confirm that you want to use it, or choose FTPS or SFTP.')]);
        }

        if ($type === 'onedrive') {
            return $this->saveOneDrive($request, $target, $new, $data);
        }

        $connectionChanged = $new || $target->type !== $type || $target->host !== $data['host'] || (int) $target->port !== (int) ($data['port'] ?? 0);
        $this->fillCommon($target, $data, $this->customer($request));
        $target->fill([
            'type' => $type, 'host' => strtolower($data['host']), 'port' => $data['port'] ?? null,
            'username' => $data['username'], 'tls' => $data['tls'] ?? 'explicit',
            'allow_plain_ftp' => $request->boolean('allow_plain_ftp'),
            'onedrive_token' => null, 'drive_id' => null, 'drive_type' => null, 'account' => null,
        ]);
        foreach (self::SECRETS as $secret) {
            if (filled($data[$secret] ?? null)) {
                $target->{$secret} = $secret === 'ssh_key' ? trim($data[$secret])."\n" : $data[$secret];
            }
        }
        if ($connectionChanged) {
            $target->host_key = null; // pin the key of the new server on the test below
        }
        if (! in_array($type, ['sftp', 'scp'], true)) {
            $target->ssh_key = null;
        }

        return $this->testAndStore($request, $target, $new, $connectionChanged);
    }

    /** OneDrive: the connection comes from "Connect with Microsoft" (kept in the session until saved). */
    private function saveOneDrive(Request $request, ClientBackupTarget $target, bool $new, array $data): RedirectResponse
    {
        $customer = $this->customer($request);
        $connection = session('backups.onedrive.connection');
        $connection = is_array($connection) && ($connection['customer_id'] ?? null) === $customer->id ? $connection : null;
        if (! $connection && ($target->type !== 'onedrive' || blank($target->onedrive_token))) {
            return back()->withInput($request->except(self::SECRETS))
                ->withErrors(['onedrive' => __('Connect with Microsoft first.')]);
        }
        $this->fillCommon($target, $data, $customer);
        $target->fill(['type' => 'onedrive', 'host' => null, 'port' => null, 'username' => null, 'tls' => 'explicit',
            'allow_plain_ftp' => false, 'password' => null, 'ssh_key' => null, 'host_key' => null]);
        if ($connection) {
            $target->fill(['onedrive_token' => $connection['token'], 'drive_id' => $connection['drive_id'],
                'drive_type' => $connection['drive_type'], 'account' => $connection['account']]);
        }
        $response = $this->testAndStore($request, $target, $new, false);
        if ($target->exists) {
            session()->forget('backups.onedrive.connection');
        }

        return $response;
    }

    private function fillCommon(ClientBackupTarget $target, array $data, $customer): void
    {
        $mine = $customer->websites()->pluck('id')->all();
        $target->fill([
            'name' => $data['name'], 'path' => trim((string) ($data['path'] ?? ''), '/ '), 'schedule' => $data['schedule'],
            'schedule_time' => $data['schedule_time'], 'full_day' => $data['full_day'], 'keep_full' => (int) $data['keep_full'],
            'parts' => array_values($data['parts']),
            'website_ids' => array_values(array_intersect(array_map('intval', $data['websites'] ?? []), $mine)) ?: null,
        ]);
    }

    private function testAndStore(Request $request, ClientBackupTarget $target, bool $new, bool $connectionChanged): RedirectResponse
    {
        // Test before saving: a location that does not work is not stored.
        $error = $this->probe($target, $request, persist: false);
        if ($error) {
            return back()->withInput($request->except(self::SECRETS))
                ->withErrors(['agent' => __('Cannot reach the location: :message', ['message' => $error])]);
        }
        $target->save();
        ActivityLog::record($new ? 'client_backup.location_added' : 'client_backup.location_changed', $target->name,
            ['type' => $target->type, 'host' => $target->host ?? $target->account], $target->customer_id);

        $status = $new ? __('Backup location :name added.', ['name' => $target->name]) : __('Backup location :name saved.', ['name' => $target->name]);
        if ($target->host_key && $connectionChanged) {
            $status .= ' '.__('Server key remembered: :fp', ['fp' => implode(', ', session('backups.fingerprints', []))]);
        }

        return redirect()->route('client.backups.locations.show', $target->id)->with('status', $status);
    }

    /** Test the location; pins the SSH host key on the first connection. Returns an error message or null. */
    private function probe(ClientBackupTarget $target, Request $request, bool $persist = true): ?string
    {
        $website = $this->customer($request)->websites()->orderBy('domain')->first();
        try {
            $result = $this->agent->call('client_backup.target_test', [
                'domain' => $website?->domain ?? 'test.invalid', 'target' => $target->agentTarget(),
            ], 150);
        } catch (AgentException $e) {
            $target->last_error = $e->getMessage();
            if ($persist) {
                $target->save();
            }

            return $e->getMessage();
        }
        if ($target->type === 'onedrive' && ! empty($result['token']) && is_array($result['token'])) {
            $target->onedrive_token = json_encode($result['token'], JSON_UNESCAPED_SLASHES);
        }
        if (! empty($result['host_key']) && ! $target->host_key) {
            $target->host_key = $result['host_key'];
        }
        session()->flash('backups.fingerprints', $result['fingerprints'] ?? []);
        $target->tested_at = now();
        $target->last_error = null;
        if ($persist) {
            $target->save();
        }

        return null;
    }

    private function find(Request $request, int $id): ClientBackupTarget
    {
        abort_unless(config('backups.customer_targets'), 404);

        return ClientBackupTarget::where('customer_id', $this->customer($request)->id)->findOrFail($id);
    }

    private function customer(Request $request)
    {
        abort_unless(config('backups.customer_targets'), 404);
        $customer = $request->user()->customer;
        abort_unless($customer !== null, 404);

        return $customer;
    }
}
