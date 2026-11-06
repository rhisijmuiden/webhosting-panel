<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Jobs;

use App\Models\ActivityLog;
use App\Services\WebsiteManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\AgentJobs;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\Present;
use Throwable;

/** Final full backup first; only when it succeeded the website is removed. */
final class TerminateSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public string $subscriptionId, public string $jobId, public int $keepBackupDays, public string $previousStatus) {}

    public function handle(AgentJobs $agentJobs, WebsiteManager $websites, Webhooks $webhooks): void
    {
        $job = CrmJob::findOrFail($this->jobId);
        $s = Subscription::findOrFail($this->subscriptionId);
        $website = $s->website;

        try {
            if ($website) {
                $job->step('Final backup', 20);
                $result = $agentJobs->run('backup.run', ['full' => true, 'scopes' => ['site:'.$website->domain, 'mail:'.$website->domain]]);
                if (($result['status'] ?? 'ok') === 'failed') {
                    throw new \RuntimeException('The final backup failed, nothing was removed');
                }
                $job->step('Removing website', 70);
                $websites->remove($website, false);
            }
        } catch (Throwable $e) {
            $s->update(['status' => $this->previousStatus]);
            $job->fail($e->getMessage());

            return;
        }

        $s->update(['status' => 'terminated', 'terminated_at' => now(), 'keep_backup_until' => now()->addDays($this->keepBackupDays)]);
        if (! Subscription::where('customer_id', $s->customer_id)->whereIn('status', ['active', 'suspended', 'provisioning'])->exists()) {
            $s->customer->update(['hosting_package_id' => null]);
        }
        $job->succeed();
        ActivityLog::record('crm.subscription_terminated', $s->primary_domain, ['keep_backup_days' => $this->keepBackupDays], $s->customer_id);
        $webhooks->dispatch('subscription.terminated', ['subscription' => Present::subscription($s->fresh())]);
    }
}
