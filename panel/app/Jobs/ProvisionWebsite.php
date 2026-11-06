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
        $subscription = $w->effectiveSubscription();
        try {
            // The subscription's resource jail first, so the site starts inside it.
            if ($subscription) {
                $limits->slice($subscription);
            }
            $site = $agent->call('site.add', array_filter([
                'domain' => $w->domain,
                'php' => $w->php_version,
                'profile' => $w->profile,
                'cache' => $w->page_cache,
                'ssl' => $this->ssl,
                'no_alias' => ! $this->wwwAlias,
                'quota_mb' => $w->disk_quota_mb,
                'subscription_id' => $subscription?->id,
                'php_workers' => $php->workers($w),
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
