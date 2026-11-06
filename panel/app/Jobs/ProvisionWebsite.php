<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\PhpSettings;
use App\Services\ResourceLimits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Creates the site on the server: user, PHP pool, Nginx vhost and Let's Encrypt certificate. */
class ProvisionWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Website $website, public bool $ssl = true, public bool $wwwAlias = true) {}

    public function handle(AgentClient $agent, ?ResourceLimits $limits = null, ?PhpSettings $php = null): void
    {
        $limits ??= app(ResourceLimits::class);
        $php ??= app(PhpSettings::class);
        $w = $this->website;
        $customer = $w->customer;
        try {
            // The customer's resource jail first, so the site starts inside it.
            if ($customer) {
                $agent->call('customer.limits', [
                    'customer_id' => $customer->id,
                    'cpu_percent' => $customer->package?->cpu_percent,
                    'memory_mb' => $customer->package?->memory_mb,
                    'io_mbps' => $customer->package?->io_mbps,
                    'max_processes' => $customer->package?->max_processes,
                ], 120);
            }
            $site = $agent->call('site.add', array_filter([
                'domain' => $w->domain,
                'php' => $w->php_version,
                'profile' => $w->profile,
                'cache' => $w->page_cache,
                'ssl' => $this->ssl,
                'no_alias' => ! $this->wwwAlias,
                'quota_mb' => $w->disk_quota_mb,
                'customer_id' => $customer?->id,
                'php_workers' => $php->workers($w),
                // A subdomain shares the disk quota of its main website.
                'parent' => $w->parent_id ? $w->parent?->domain : null,
            ], fn ($v) => $v !== null), 840);
            $w->fillFromAgent($site);
            try {
                $limits->applyWebsite($w);
            } catch (AgentException $e) {
                ActivityLog::record('website.limits_failed', $w->domain, ['error' => $e->getMessage()], $w->customer_id);
            }
            $w->status = 'active';
            $w->last_error = null;
        } catch (AgentException $e) {
            $w->status = 'failed';
            $w->last_error = $e->getMessage();
        }
        $w->save();
    }
}
