<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Creates the site on the server: user, PHP pool, Nginx vhost and Let's Encrypt certificate. */
class ProvisionWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Website $website, public bool $ssl = true, public bool $wwwAlias = true) {}

    public function handle(AgentClient $agent): void
    {
        $w = $this->website;
        try {
            $site = $agent->call('site.add', array_filter([
                'domain' => $w->domain,
                'php' => $w->php_version,
                'profile' => $w->profile,
                'cache' => $w->page_cache,
                'ssl' => $this->ssl,
                'no_alias' => ! $this->wwwAlias,
                'quota_mb' => $w->disk_quota_mb,
            ], fn ($v) => $v !== null), 840);
            $w->fillFromAgent($site);
            $w->status = 'active';
            $w->last_error = null;
        } catch (AgentException $e) {
            $w->status = 'failed';
            $w->last_error = $e->getMessage();
        }
        $w->save();
    }
}
