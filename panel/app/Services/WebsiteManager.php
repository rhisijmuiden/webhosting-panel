<?php

namespace App\Services;

use App\Jobs\ProvisionWebsite;
use App\Jobs\RemoveWebsite;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;

/** Every change to a website goes through here, so admin and client panel behave the same. */
class WebsiteManager
{
    public function __construct(private readonly AgentClient $agent) {}

    public function create(Customer $customer, array $data): Website
    {
        $website = $customer->websites()->create([
            'domain' => $data['domain'],
            'profile' => $data['profile'] ?? 'php',
            'php_version' => $data['php_version'] ?? '8.4',
            'page_cache' => $data['profile'] === 'laravel' ? false : ($data['page_cache'] ?? true),
            'ssl_status' => ($data['ssl'] ?? true) ? 'pending' : 'off',
            'status' => 'provisioning',
            'disk_quota_mb' => $customer->package?->disk_mb,
        ]);
        ActivityLog::record('website.created', $website->domain, customerId: $customer->id);
        ProvisionWebsite::dispatch($website, (bool) ($data['ssl'] ?? true), (bool) ($data['www_alias'] ?? true));

        return $website;
    }

    public function setPhp(Website $website, string $version): void
    {
        $this->agent->call('site.php', ['domain' => $website->domain, 'php' => $version], 120);
        $website->update(['php_version' => $version]);
        ActivityLog::record('website.php_changed', $website->domain, ['php' => $version], $website->customer_id);
    }

    public function setCache(Website $website, bool $enabled): void
    {
        $this->agent->call('site.cache', ['domain' => $website->domain, 'enabled' => $enabled], 60);
        $website->update(['page_cache' => $enabled]);
        ActivityLog::record('website.cache_changed', $website->domain, ['enabled' => $enabled], $website->customer_id);
    }

    public function issueSsl(Website $website): void
    {
        $site = $this->agent->call('site.ssl', ['domain' => $website->domain], 300);
        $website->update(['ssl_status' => $site['ssl'] ?? 'pending']);
        ActivityLog::record('website.ssl_requested', $website->domain, customerId: $website->customer_id);
    }

    public function remove(Website $website, bool $keepFiles): void
    {
        $website->update(['status' => 'removing']);
        ActivityLog::record('website.removed', $website->domain, ['keep_files' => $keepFiles], $website->customer_id);
        RemoveWebsite::dispatch($website, $keepFiles);
    }
}
