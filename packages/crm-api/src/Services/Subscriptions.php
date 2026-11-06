<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Illuminate\Support\Facades\DB;
use Rhis\CrmApi\Jobs\ApplyPlan;
use Rhis\CrmApi\Jobs\RestoreSubscription;
use Rhis\CrmApi\Jobs\TerminateSubscription;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Present;

/** Provisioning, plan changes, suspension and termination for the CRM. */
final class Subscriptions
{
    public function __construct(
        private readonly WebsiteManager $websites,
        private readonly AgentClient $agent,
        private readonly Webhooks $webhooks,
    ) {}

    public function create(Customer $customer, HostingPackage $plan, array $data): array
    {
        if (! $plan->is_active) {
            throw ApiError::conflict('This plan is not active');
        }
        if (Website::where('domain', $data['primary_domain'])->exists()) {
            throw ApiError::conflict('This domain is already hosted on this server');
        }

        [$subscription, $job] = DB::transaction(function () use ($customer, $plan, $data) {
            $customer->update(['hosting_package_id' => $plan->id]);
            $subscription = Subscription::create([
                'external_id' => $data['external_id'], 'customer_id' => $customer->id, 'hosting_package_id' => $plan->id,
                'primary_domain' => $data['primary_domain'], 'status' => 'provisioning',
                'php_version' => $data['php_version'] ?? '8.4', 'limit_overrides' => $data['limit_overrides'] ?? null,
                'options' => array_filter(['dns' => $data['dns'] ?? null, 'mail' => $data['mail'] ?? null, 'install' => $data['install'] ?? null]),
            ]);
            $job = CrmJob::create(['type' => 'subscription.provision', 'status' => 'running', 'step' => 'Creating website', 'progress' => 10, 'subscription_id' => $subscription->id]);

            return [$subscription, $job];
        });

        $profile = ($data['install']['app'] ?? null) === 'wordpress' ? 'wordpress' : 'php';
        // ProvisionWebsite runs on the queue; SubscriptionObserver finishes the job when the site is active or failed.
        $website = $this->websites->create($customer->fresh('package'), [
            'domain' => $data['primary_domain'], 'php_version' => $subscription->php_version, 'profile' => $profile,
            'ssl' => true, 'www_alias' => true,
        ]);
        if ($quota = ($data['limit_overrides']['disk_mb'] ?? null)) {
            $website->updateQuietly(['disk_quota_mb' => $quota]);
        }
        $subscription->update(['website_id' => $website->id]);
        self::finishProvisioning($website->fresh());
        ActivityLog::record('crm.subscription_created', $subscription->primary_domain, ['external_id' => $subscription->external_id], $customer->id);

        return [$subscription->fresh(), $job->fresh()];
    }

    /** Called when the website of a provisioning subscription reaches its final state. */
    public static function finishProvisioning(Website $website): void
    {
        if (! in_array($website->status, ['active', 'failed'], true)) {
            return;
        }
        $subscription = Subscription::where('website_id', $website->id)->where('status', 'provisioning')->first();
        if (! $subscription) {
            return;
        }
        $job = CrmJob::where('subscription_id', $subscription->id)->where('type', 'subscription.provision')->where('status', 'running')->first();
        if ($website->status === 'active') {
            $subscription->update(['status' => 'active']);
            $job?->succeed();
            app(Webhooks::class)->dispatch('subscription.provisioned', ['subscription' => Present::subscription($subscription->fresh())]);
        } else {
            $subscription->update(['status' => 'failed']);
            $job?->fail($website->last_error ?: 'Provisioning failed');
            app(Webhooks::class)->dispatch('subscription.failed', ['subscription' => Present::subscription($subscription->fresh()), 'error' => $website->last_error]);
        }
    }

    public function change(Subscription $s, array $data): CrmJob
    {
        $this->assertStatus($s, ['active', 'suspended']);
        $job = CrmJob::create(['type' => 'subscription.change_plan', 'status' => 'queued', 'subscription_id' => $s->id]);
        ApplyPlan::dispatch($s->id, $job->id, $data);

        return $job;
    }

    public function suspend(Subscription $s, array $data): Subscription
    {
        $this->assertStatus($s, ['active']);
        $s->update(['status' => 'suspended', 'suspension' => [
            'reason' => $data['reason'], 'note' => $data['note'] ?? null, 'since' => now()->toIso8601String(),
            'keep_mail_flowing' => (bool) ($data['keep_mail_flowing'] ?? true),
        ]]);
        $this->siteState($s, 'suspend');
        $this->syncCustomerStatus($s->customer);
        ActivityLog::record('crm.subscription_suspended', $s->primary_domain, ['reason' => $data['reason']], $s->customer_id);
        $this->webhooks->dispatch('subscription.suspended', ['subscription' => Present::subscription($s->fresh())]);

        return $s->fresh();
    }

    public function unsuspend(Subscription $s): Subscription
    {
        $this->assertStatus($s, ['suspended']);
        $s->update(['status' => 'active', 'suspension' => null]);
        $this->siteState($s, 'unsuspend');
        $this->syncCustomerStatus($s->customer);
        ActivityLog::record('crm.subscription_unsuspended', $s->primary_domain, customerId: $s->customer_id);
        $this->webhooks->dispatch('subscription.unsuspended', ['subscription' => Present::subscription($s->fresh())]);

        return $s->fresh();
    }

    public function terminate(Subscription $s, int $keepBackupDays): CrmJob
    {
        $this->assertStatus($s, ['active', 'suspended', 'failed']);
        $job = CrmJob::create(['type' => 'subscription.terminate', 'status' => 'queued', 'subscription_id' => $s->id]);
        $previous = $s->status;
        $s->update(['status' => 'terminating']);
        TerminateSubscription::dispatch($s->id, $job->id, $keepBackupDays, $previous);

        return $job;
    }

    public function restore(Subscription $s): CrmJob
    {
        $this->assertStatus($s, ['terminated']);
        if (! $s->keep_backup_until || $s->keep_backup_until->isPast()) {
            throw ApiError::conflict('The final backup of this subscription is no longer kept');
        }
        if (Website::where('domain', $s->primary_domain)->exists()) {
            throw ApiError::conflict('This domain is hosted again already');
        }
        $job = CrmJob::create(['type' => 'subscription.restore', 'status' => 'queued', 'subscription_id' => $s->id]);
        $s->update(['status' => 'provisioning']);
        RestoreSubscription::dispatch($s->id, $job->id);

        return $job;
    }

    private function assertStatus(Subscription $s, array $allowed): void
    {
        if (! in_array($s->status, $allowed, true)) {
            throw ApiError::conflict("Not possible while the subscription is {$s->status}");
        }
    }

    /** The customer login is blocked while all of their hosting is suspended. */
    private function syncCustomerStatus(Customer $customer): void
    {
        $active = Subscription::where('customer_id', $customer->id)->where('status', 'active')->exists();
        $suspended = Subscription::where('customer_id', $customer->id)->where('status', 'suspended')->exists();
        $customer->update(['status' => ($suspended && ! $active) ? 'suspended' : 'active']);
    }

    /** Show the suspension page on the server, when the agent supports it. */
    private function siteState(Subscription $s, string $state): void
    {
        $website = $s->website;
        if (! $website) {
            return;
        }
        $website->update(['status' => $state === 'suspend' ? 'suspended' : 'active']);
        try {
            $this->agent->call("site.{$state}", ['domain' => $website->domain], 120);
        } catch (AgentException $e) {
            // The login is blocked either way; the server side is retried by the admin.
            ActivityLog::record('crm.site_state_not_applied', $website->domain, ['state' => $state, 'error' => $e->getMessage()], $s->customer_id);
        }
    }
}
