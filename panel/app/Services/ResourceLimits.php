<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The resource jail of a customer. All websites of a customer run in one systemd
 * slice. Without caps the customers share the server fairly (equal CPU and disk
 * weight); with the caps of the package they can never use more than that,
 * whatever their sites do. Database users get a connection cap per website.
 */
class ResourceLimits
{
    public function __construct(private readonly AgentClient $agent, private readonly PhpSettings $php) {}

    /** Apply the package to the server for every website of the customer. Returns the errors. */
    public function apply(Customer $customer): array
    {
        $package = $customer->package;
        $errors = [];
        try {
            $this->agent->call('customer.limits', [
                'customer_id' => $customer->id,
                'cpu_percent' => $package?->cpu_percent,
                'memory_mb' => $package?->memory_mb,
                'io_mbps' => $package?->io_mbps,
                'max_processes' => $package?->max_processes,
            ], 120);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        foreach ($customer->websites()->where('status', '!=', 'provisioning')->where('status', '!=', 'removing')->get() as $website) {
            try {
                $this->applyWebsite($website);
            } catch (AgentException $e) {
                $errors[] = $website->domain.': '.$e->getMessage();
            }
        }
        ActivityLog::record('customer.limits_applied', $customer->displayName(), $errors ? ['errors' => $errors] : [], $customer->id);

        return $errors;
    }

    /** The per-website part: slice, PHP workers and settings, database connections. */
    public function applyWebsite(Website $website): void
    {
        $customer = $website->customer;
        $package = $customer?->package;
        $this->agent->call('site.owner', ['domain' => $website->domain, 'customer_id' => $customer?->id], 120);
        $this->php->apply($website);
        $this->agent->call('db.limits', ['domain' => $website->domain, 'max_connections' => $package?->db_connections], 60);
    }
}
