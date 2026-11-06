<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Jobs;

use App\Jobs\ProvisionWebsite;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\AgentJobs;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\Present;
use RuntimeException;
use Throwable;

/** Bring a terminated subscription back: recreate the site, then restore its last backup. */
final class RestoreSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public string $subscriptionId, public string $jobId) {}

    public function handle(AgentClient $agent, AgentJobs $agentJobs, Webhooks $webhooks): void
    {
        $job = CrmJob::findOrFail($this->jobId);
        $s = Subscription::findOrFail($this->subscriptionId);

        try {
            $job->step('Recreating website', 10);
            $s->customer->update(['hosting_package_id' => $s->hosting_package_id]);
            $website = $s->customer->websites()->create([
                'domain' => $s->primary_domain, 'php_version' => $s->php_version, 'status' => 'provisioning',
                'ssl_status' => 'pending', 'disk_quota_mb' => $s->limit_overrides['disk_mb'] ?? $s->package?->disk_mb,
            ]);
            $s->update(['website_id' => $website->id]);
            (new ProvisionWebsite($website))->handle($agent);
            if ($website->fresh()->status !== 'active') {
                throw new RuntimeException('Could not recreate the website: '.$website->fresh()->last_error);
            }

            $job->step('Restoring files', 50);
            $target = collect($agent->call('backup.targets')['targets'] ?? [])->firstWhere('enabled', true)['name'] ?? null;
            if (! $target) {
                throw new RuntimeException('No backup location configured');
            }
            foreach (['site', 'mail'] as $kind) {
                $snapshots = $agent->call('backup.list', ['target' => $target, 'scope' => $kind.':'.$s->primary_domain], 180)['snapshots'] ?? [];
                $latest = collect($snapshots)->firstWhere(fn ($x) => ($x['kind'] ?? '') !== 'pre-restore');
                if ($latest) {
                    $agentJobs->run('backup.restore', ['target' => $target, 'snapshot' => $latest['id'], 'mode' => 'in_place']);
                } elseif ($kind === 'site') {
                    throw new RuntimeException('No backup of the website found');
                }
            }
        } catch (Throwable $e) {
            $s->update(['status' => 'failed']);
            $job->fail($e->getMessage());

            return;
        }

        $s->update(['status' => 'active', 'terminated_at' => null, 'keep_backup_until' => null]);
        $job->succeed();
        ActivityLog::record('crm.subscription_restored', $s->primary_domain, customerId: $s->customer_id);
        $webhooks->dispatch('subscription.provisioned', ['subscription' => Present::subscription($s->fresh())]);
    }
}
