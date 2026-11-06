<?php

namespace App\Services;

use App\Jobs\ProvisionWebsite;
use App\Jobs\RemoveWebsite;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Website;
use App\Services\Agent\AgentClient;

/** Every change to a website goes through here, so admin and client panel behave the same. */
class WebsiteManager
{
    public function __construct(private readonly AgentClient $agent, private readonly PhpSettings $php) {}

    /**
     * A new website is a new subscription: $data['hosting_package_id'] (null: no package,
     * everything unlimited) and optionally $data['expires_on'].
     */
    public function create(Customer $customer, array $data): Website
    {
        $package = isset($data['hosting_package_id']) ? HostingPackage::find($data['hosting_package_id']) : null;
        $website = $customer->websites()->make([
            'domain' => $data['domain'],
            'profile' => $data['profile'] ?? 'php',
            'php_version' => $data['php_version'] ?? '8.4',
            'page_cache' => ($data['profile'] ?? 'php') === 'laravel' ? false : ($data['page_cache'] ?? true),
            'ssl_status' => ($data['ssl'] ?? true) ? 'pending' : 'off',
            'status' => 'provisioning',
            'disk_quota_mb' => $package?->disk_mb,
        ]);
        $website->initialPackageId = $package?->id;
        $website->save();
        if (! empty($data['expires_on'])) {
            $website->subscription->update(['expires_on' => $data['expires_on']]);
        }
        ActivityLog::record('website.created', $website->domain, ['package' => $package?->name], $customer->id);
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

    /**
     * Save the website's own PHP values (missing = server default) and apply them.
     * Values above the server maximum are lowered to it (PhpSettings::effective).
     */
    public function setPhpSettings(Website $website, array $values): void
    {
        $previous = $website->php_settings;
        $website->update(['php_settings' => $values ?: null]);
        try {
            $this->php->apply($website);
        } catch (\Throwable $e) {
            $website->update(['php_settings' => $previous]);
            throw $e;
        }
        ActivityLog::record('website.php_settings_changed', $website->domain, $values, $website->customer_id);
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
