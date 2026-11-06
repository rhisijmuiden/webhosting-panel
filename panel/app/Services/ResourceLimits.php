<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The resource jail of a subscription. Every subscription (one website on one
 * package) runs in its own systemd slice whp-sites-s<id>.slice. Without caps the
 * slices share the server fairly (equal CPU and disk weight); with the caps of
 * the package that website can never use more than that, whatever it does.
 * The database user gets a connection cap.
 */
class ResourceLimits
{
    public function __construct(private readonly AgentClient $agent, private readonly PhpSettings $php) {}

    /** The slice of the subscription with the caps of its package (call before the site joins it). */
    public function slice(Subscription $subscription): void
    {
        $package = $subscription->package;
        $this->agent->call('subscription.limits', [
            'subscription_id' => $subscription->id,
            'cpu_percent' => $package?->cpu_percent,
            'memory_mb' => $package?->memory_mb,
            'io_mbps' => $package?->io_mbps,
            'max_processes' => $package?->max_processes,
        ], 120);
    }

    /** Apply the package of the subscription to the server. Returns the errors. */
    public function apply(Subscription $subscription): array
    {
        $website = $subscription->website;
        $errors = [];
        try {
            $this->slice($subscription);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        if ($website && ! in_array($website->status, ['provisioning', 'removing'], true)) {
            try {
                $this->applyWebsite($website);
            } catch (AgentException $e) {
                $errors[] = $website->domain.': '.$e->getMessage();
            }
        }
        ActivityLog::record('subscription.limits_applied', $website?->domain, $errors ? ['errors' => $errors] : [], $website?->customer_id);

        return $errors;
    }

    /** Every subscription of a customer. Returns the errors. */
    public function applyCustomer(Customer $customer): array
    {
        $errors = [];
        foreach (Subscription::ownedBy($customer)->with('package', 'website')->get() as $subscription) {
            $errors = array_merge($errors, $this->apply($subscription));
        }

        return $errors;
    }

    /** The per-website part: slice membership, PHP workers and settings, database connections. */
    public function applyWebsite(Website $website): void
    {
        $subscription = $website->effectiveSubscription();
        $this->agent->call('site.owner', array_filter([
            'domain' => $website->domain,
            'subscription_id' => $subscription?->id,
        ]), 120);
        $this->php->apply($website);
        $this->agent->call('db.limits', ['domain' => $website->domain, 'max_connections' => $subscription?->package?->db_connections], 60);
    }
}
