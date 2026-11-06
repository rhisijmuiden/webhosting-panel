<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Jobs;

use App\Models\ActivityLog;
use App\Models\HostingPackage;
use App\Services\Agent\AgentClient;
use App\Services\SubscriptionManager;
use App\Services\WebsiteManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\Present;
use Throwable;

/** Plan change, limit overrides or PHP version. */
final class ApplyPlan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $subscriptionId, public string $jobId, public array $data) {}

    public function handle(AgentClient $agent, WebsiteManager $websites, Webhooks $webhooks, SubscriptionManager $subscriptions): void
    {
        $job = CrmJob::findOrFail($this->jobId);
        $s = Subscription::findOrFail($this->subscriptionId);
        try {
            $job->step('Applying limits', 30);
            $changes = [];
            if (isset($this->data['plan_id'])) {
                $s->hosting_package_id = (int) $this->data['plan_id'];
                $changes['plan_id'] = $this->data['plan_id'];
            }
            if (array_key_exists('limit_overrides', $this->data)) {
                $s->limit_overrides = $this->data['limit_overrides'];
                $changes['limit_overrides'] = $this->data['limit_overrides'];
            }
            if (isset($this->data['external_id'])) {
                $s->external_id = $this->data['external_id'];
            }
            $s->save();

            $website = $s->website;
            if ($website && isset($changes['plan_id']) && $website->subscription) {
                // The panel subscription of the website follows the plan (the quota is set below).
                $subscriptions->changePackage($website->subscription, HostingPackage::find($s->hosting_package_id), quota: false);
            }
            if ($website) {
                $disk = $s->limit_overrides['disk_mb'] ?? HostingPackage::find($s->hosting_package_id)?->disk_mb;
                if ($disk !== null && (int) $disk !== (int) $website->disk_quota_mb) {
                    $agent->call('site.quota', ['domain' => $website->domain, 'quota_mb' => (int) $disk], 120);
                    $website->update(['disk_quota_mb' => (int) $disk]);
                }
                if (isset($this->data['php_version']) && $this->data['php_version'] !== $website->php_version) {
                    $job->step('Switching PHP version', 70);
                    $websites->setPhp($website, $this->data['php_version']);
                    $changes['php_version'] = $this->data['php_version'];
                }
            }
            $job->succeed();
            ActivityLog::record('crm.subscription_changed', $s->primary_domain, $changes, $s->customer_id);
            $webhooks->dispatch('subscription.changed', ['subscription' => Present::subscription($s->fresh())]);
        } catch (Throwable $e) {
            $job->fail($e->getMessage());
        }
    }
}
