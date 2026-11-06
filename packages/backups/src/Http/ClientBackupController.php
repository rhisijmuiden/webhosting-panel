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
use Illuminate\View\View;
use Rhis\Backups\BackupJobs;

/** Customers see the backups of their own websites and can put one back. */
final class ClientBackupController extends Controller
{
    public function __construct(private readonly AgentClient $agent) {}

    public function index(Request $request): View
    {
        abort_unless(config('backups.customer_restore'), 404);
        $websites = $request->user()->customer->websites()->orderBy('domain')->get();
        $error = null;
        $byDomain = [];
        try {
            $target = $this->target();
            foreach ($websites as $site) {
                $byDomain[$site->domain] = $target
                    ? array_slice($this->snapshots($target, $site), 0, 30)
                    : [];
            }
        } catch (AgentException $e) {
            $error = $e->getMessage();
        }

        return view('backups::client.index', [
            'websites' => $websites, 'snapshots' => $byDomain, 'error' => $error,
            'jobs' => BackupJobs::recent($request->user()->customer_id, 5),
        ]);
    }

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
            $target = $this->target();
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
        abort_unless($meta && $meta['customer_id'] === $request->user()->customer_id, 404);
        $errors = [];
        try {
            $state = $this->agent->call('job.status', ['job' => $job], 10);
            // Customers see the outcome, not the server log.
            unset($state['log']);
        } catch (AgentException $e) {
            $state = null;
            $errors[] = $e->getMessage();
        }

        return view('backups::client.job', ['job' => $state, 'meta' => $meta, 'agentErrors' => $errors]);
    }

    private function target(): ?string
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
