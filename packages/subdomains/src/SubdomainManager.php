<?php

declare(strict_types=1);

namespace Rhis\Subdomains;

use App\Jobs\ProvisionWebsite;
use App\Models\ActivityLog;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subdomains (test.example.com) of a main website. Each one is a website of
 * its own on the server (own Linux user, PHP pool, certificate), but it is
 * part of the main website's subscription: it shares its disk quota and
 * resource limits and is never a hosting package of its own.
 */
final class SubdomainManager
{
    public const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private readonly AgentClient $agent, private readonly WebsiteManager $websites) {}

    public function limit(Website $parent): ?int
    {
        $max = config('subdomains.max_per_domain');

        return $max === null ? null : (int) $max;
    }

    /** Why a subdomain cannot be added to this website right now (null: it can). */
    public function blocker(Website $parent): ?string
    {
        if ($parent->isSubdomain()) {
            return __('A subdomain cannot have subdomains of its own.');
        }
        if ($parent->status !== 'active') {
            return __('The website is not active.');
        }
        $limit = $this->limit($parent);
        if ($limit !== null && $parent->subdomains()->count() >= $limit) {
            return __('This domain already has :count subdomains, the maximum.', ['count' => $limit]);
        }

        return null;
    }

    /** The error for a subdomain name, or null when it is free to use. */
    public function invalid(Website $parent, string $label): ?string
    {
        if (! preg_match(self::LABEL, $label)) {
            return __('Use lowercase letters, digits and - only, for example test or shop.');
        }
        if (in_array($label, (array) config('subdomains.reserved', []), true)) {
            return __(':name is reserved for the mail and DNS services.', ['name' => $label]);
        }
        $domain = $label.'.'.$parent->domain;
        if (strlen($domain) > 253) {
            return __('The name is too long.');
        }
        if (Website::where('domain', $domain)->exists() || in_array($domain, (array) $parent->aliases, true)) {
            return __(':domain is already in use.', ['domain' => $domain]);
        }
        if (Schema::hasTable('domain_aliases') && DB::table('domain_aliases')->where('domain', $domain)->exists()) {
            return __(':domain is already in use.', ['domain' => $domain]);
        }

        return null;
    }

    /**
     * @param  array{php_version?: string, profile?: string, page_cache?: bool, ssl?: bool}  $options
     */
    public function create(Website $parent, string $label, array $options = []): Website
    {
        $label = strtolower(trim($label));
        if ($error = $this->blocker($parent) ?? $this->invalid($parent, $label)) {
            throw new SubdomainException($error);
        }
        $profile = $options['profile'] ?? 'php';
        $ssl = (bool) ($options['ssl'] ?? true);

        // parent_id is set before the first save: the subscription and DNS hooks look at it.
        $website = new Website([
            'customer_id' => $parent->customer_id,
            'domain' => $label.'.'.$parent->domain,
            'profile' => $profile,
            'php_version' => $options['php_version'] ?? $parent->php_version ?? '8.4',
            'page_cache' => $profile === 'laravel' ? false : (bool) ($options['page_cache'] ?? true),
            'ssl_status' => $ssl ? 'pending' : 'off',
            'status' => 'provisioning',
        ]);
        $website->parent_id = $parent->id;
        $website->save();
        ActivityLog::record('subdomain.created', $website->domain, ['parent' => $parent->domain], $parent->customer_id);
        ProvisionWebsite::dispatch($website, $ssl, false);

        return $website->fresh() ?? $website;
    }

    /** Remove a subdomain with its files and the databases that belong to it. */
    public function remove(Website $subdomain): void
    {
        if (! $subdomain->isSubdomain()) {
            throw new SubdomainException(__('Only subdomains can be removed here.'));
        }
        if (in_array($subdomain->status, ['provisioning', 'removing'], true)) {
            throw new SubdomainException(__('Wait until the current task is finished.'));
        }
        foreach ($subdomain->databases as $db) {
            try {
                $this->agent->call('db.drop', ['name' => $db->name, 'domain' => $subdomain->domain], 120);
            } catch (AgentException $e) {
                if ($e->errorCode !== 'not_found') {
                    throw $e;
                }
            }
            $db->delete();
        }
        ActivityLog::record('subdomain.removed', $subdomain->domain, ['parent' => $subdomain->parent?->domain], $subdomain->customer_id);
        $this->websites->remove($subdomain, false);
    }
}
