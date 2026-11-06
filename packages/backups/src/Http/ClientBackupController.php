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
use Rhis\Backups\BackupJobs;
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;

/**
 * Customer backups: own backup locations, backups made by the customer, and
 * the nightly server backups of their websites (restore only).
 */
final class ClientBackupController extends Controller
{
    public function __construct(private readonly AgentClient $agent, private readonly ClientBackups $backups) {}

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer && (config('backups.customer_restore') || config('backups.customer_targets')), 404);
        $websites = $customer->websites()->orderBy('domain')->get();

        $error = null;
        $byDomain = [];
        if (config('backups.customer_restore')) {
            try {
                $target = $this->serverTarget();
                foreach ($websites as $site) {
                    $byDomain[$site->domain] = $target ? array_slice($this->snapshots($target, $site), 0, 30) : [];
                }
            } catch (AgentException $e) {
                $error = $e->getMessage();
            }
        }

        $history = ClientBackup::where('customer_id', $customer->id)->with('target')->latest('started_at')->limit(15)->get();
        foreach ($history->where('status', 'running') as $record) {
            $this->backups->sync($record);
        }

        return view('backups::client.index', [
            'websites' => $websites, 'snapshots' => $byDomain, 'error' => $error,
            'jobs' => BackupJobs::recent($request->user()->customer_id, 5),
            'targets' => config('backups.customer_targets') ? ClientBackupTarget::where('customer_id', $customer->id)->orderBy('name')->get() : collect(),
            'history' => $history,
            'tab' => in_array($request->query('tab'), ['server', 'upload'], true) ? $request->query('tab') : 'own',
        ]);
    }

    /** Back up one website (or all) to one of the customer's own locations, now. */
    public function run(Request $request): RedirectResponse
    {
        abort_unless(config('backups.customer_targets'), 404);
        $customer = $request->user()->customer;
        $data = $request->validate([
            'target' => ['required', 'integer'],
            'website' => ['required'],
            'kind' => ['required', Rule::in(['full', 'incremental'])],
        ]);
        $target = ClientBackupTarget::where('customer_id', $customer->id)->findOrFail($data['target']);
        $websites = $data['website'] === 'all'
            ? $target->websites()
            : Website::where('customer_id', $customer->id)->whereKey((int) $data['website'])->get();
        abort_if($websites->isEmpty(), 404);

        $started = 0;
        foreach ($websites as $website) {
            if (ClientBackup::where('website_id', $website->id)->where('status', 'running')->exists()) {
                continue;
            }
            try {
                $this->backups->backup($target, $website, $data['kind']);
                $started++;
            } catch (AgentException $e) {
                return back()->withErrors(['agent' => $e->getMessage()]);
            }
        }

        return redirect()->route('client.backups.index')->with('status', $started
            ? trans_choice('Backup started for :count website.|Backup started for :count websites.', $started)
            : __('A backup or restore of this website is already running.'));
    }

    /** The nightly server backup of a site put back (whp-backup / restic). */
    public function restore(Request $request): RedirectResponse
    {
        abort_unless(config('backups.customer_restore'), 404);
        $data = $request->validate([
            'website' => ['required', 'integer'],
            'snapshot' => ['required', 'regex:/^[0-9a-f]{8}$/'],
        ]);
        $website = Website::findOrFail($data['website']);
        abort_unless($website->customer_id === $request->user()->customer_id, 404);

        try {
            $target = $this->serverTarget();
            // Only a snapshot of this customer's own site may be restored.
            $found = $target ? collect($this->snapshots($target, $website))->firstWhere('short_id', $data['snapshot']) : null;
            abort_unless($found !== null, 404);
            $job = $this->agent->call('backup.restore', [
                'target' => $target, 'snapshot' => $found['id'], 'mode' => 'in_place',
            ], 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        BackupJobs::remember($job['job'], __('Restore :domain', ['domain' => $website->domain]), $website->customer_id);
        ActivityLog::record('backup.restored', $website->domain, ['snapshot' => $data['snapshot']], $website->customer_id);

        return redirect()->route('client.backups.jobs.show', $job['job']);
    }

    public function job(Request $request, string $job): View
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $job), 404);
        $meta = BackupJobs::find($job);
        $record = null;
        if (! $meta) {
            $record = ClientBackup::where('job_id', $job)->where('customer_id', $request->user()->customer_id)->first();
            abort_unless($record !== null, 404);
            $meta = ['label' => $record->label().' · '.$record->domain, 'customer_id' => $record->customer_id];
        }
        abort_unless($meta['customer_id'] === $request->user()->customer_id, 404);
        $errors = [];
        try {
            $state = $this->agent->call('job.status', ['job' => $job], 10);
            // Customers see the outcome, not the server log.
            unset($state['log']);
            if ($record) {
                $this->backups->sync($record);
            }
        } catch (AgentException $e) {
            $state = null;
            $errors[] = $e->getMessage();
        }

        return view('backups::client.job', ['job' => $state, 'meta' => $meta, 'record' => $record, 'agentErrors' => $errors]);
    }

    private function serverTarget(): ?string
    {
        $configured = config('backups.customer_target');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $targets = $this->agent->call('backup.targets')['targets'] ?? [];

        return collect($targets)->firstWhere('enabled', true)['name'] ?? null;
    }

    private function snapshots(string $target, Website $site): array
    {
        $list = $this->agent->call('backup.list', ['target' => $target, 'scope' => 'site:'.$site->domain], 180)['snapshots'] ?? [];

        // Pre-restore snapshots are a safety net for the admin, not restore points for the customer.
        return array_values(array_filter($list, fn ($s) => ($s['kind'] ?? '') !== 'pre-restore'));
    }
}
