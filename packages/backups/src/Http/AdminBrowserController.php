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
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;
use Rhis\Backups\RestorePointBrowser;

/**
 * The backup repository for the admin: pick a location and a day, then pick
 * what to put back: one item, a whole customer, or everything.
 */
final class AdminBrowserController extends Controller
{
    private const TARGET = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    private const ITEM = '/^([0-9a-f]{64})\|(system|(site|mail):[a-z0-9.-]{1,253}|db:[A-Za-z0-9_]{1,64})$/';

    public function __construct(private readonly AgentClient $agent, private readonly ClientBackups $backups) {}

    public function index(Request $request): View
    {
        $errors = [];
        $targets = [];
        try {
            $targets = $this->agent->call('backup.targets')['targets'] ?? [];
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        $selected = $request->query('target');
        if (! is_string($selected) || ! collect($targets)->contains('name', $selected)) {
            $selected = $targets[0]['name'] ?? null;
        }

        $days = $groups = [];
        $date = null;
        if ($selected) {
            try {
                $snapshots = $this->agent->call('backup.list', ['target' => $selected], 180)['snapshots'] ?? [];
                $browser = new RestorePointBrowser($snapshots, (string) config('app.timezone'));
                $days = $browser->days();
                $date = $request->query('date');
                if (! is_string($date) || ! collect($days)->contains('date', $date)) {
                    $date = $days[0]['date'] ?? null;
                }
                $groups = $date ? $browser->groups($date) : [];
            } catch (AgentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $customer = $request->query('customer');

        return view('backups::admin.browse', [
            'targets' => $targets, 'selected' => $selected, 'days' => $days, 'date' => $date, 'groups' => $groups,
            'focus' => is_string($customer) ? $customer : null, 'agentErrors' => $errors,
        ]);
    }

    public function restore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'regex:'.self::TARGET],
            'date' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::in(['in_place', 'copy'])],
            'items' => ['required', 'array', 'min:1', 'max:2000'],
            'items.*' => ['string', 'regex:'.self::ITEM],
        ], ['items.required' => __('Select at least one item to restore.')]);
        $items = collect($data['items'])->unique()->map(function ($v) {
            [$snapshot, $scope] = explode('|', $v, 2);

            return ['snapshot' => $snapshot, 'scope' => $scope];
        })->values()->all();
        $params = ['target' => $data['target'], 'mode' => $data['mode'], 'items' => $items];
        if ($data['mode'] === 'copy') {
            $params['to'] = '/root/restore/'.$data['date'];
        }

        try {
            $result = $this->agent->call('backup.restore_many', $params, 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        $label = trans_choice('Restore :count item from :date|Restore :count items from :date', count($items), ['date' => $data['date']]);
        BackupJobs::remember($result['job'], $label);
        ActivityLog::record('backup.restored', $data['target'], ['date' => $data['date'], 'mode' => $data['mode'],
            'items' => array_column($items, 'scope')]);

        return redirect()->route('admin.backups.jobs.show', $result['job']);
    }

    /** All customer backups (their own locations) at a glance. */
    public function customers(Request $request): View
    {
        foreach (ClientBackup::where('status', 'running')->limit(50)->get() as $record) {
            $this->backups->sync($record);
        }
        $status = $request->query('status');
        $query = ClientBackup::with(['customer', 'target'])->latest('started_at');
        if (in_array($status, ['running', 'succeeded', 'failed'], true)) {
            $query->where('status', $status);
        }

        return view('backups::admin.customers', [
            'records' => $query->paginate(50)->withQueryString(),
            'targets' => ClientBackupTarget::with('customer')->orderBy('customer_id')->get(),
            'status' => $status,
        ]);
    }
}
