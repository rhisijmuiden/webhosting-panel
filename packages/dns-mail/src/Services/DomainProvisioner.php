<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\DnsMail\Models\MailDomain;
use Throwable;

/**
 * Keeps the agent and Cloudflare in line after something changed. Every step
 * is best effort: a failure is returned as a message and shown to the user,
 * never thrown, so saving a form always works.
 */
final class DomainProvisioner
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly MailPusher $pusher,
        private readonly DnsSync $dns,
        private readonly DnsMailConfig $config,
    ) {
    }

    /** Called when a website is created: one mail domain per website domain. */
    public function forWebsite(Website $site): MailDomain
    {
        $domain = MailDomain::firstOrCreate(
            ['domain' => strtolower($site->domain)],
            ['customer_id' => $site->customer_id, 'website_id' => $site->id],
        );
        if ($domain->website_id === null) {
            $domain->update(['website_id' => $site->id]);
        }
        $this->afterChange($domain, withDkim: true);

        return $domain;
    }

    /** @return list<string> warnings for the flash message */
    public function afterChange(MailDomain $domain, bool $withDkim = false, bool $syncDns = true): array
    {
        $warnings = [];
        if ($withDkim && $domain->dkim_public_key === null) {
            try {
                $this->ensureDkim($domain);
            } catch (Throwable $e) {
                $warnings[] = __('DKIM key not created: :error', ['error' => $e->getMessage()]);
            }
        }
        try {
            $this->pusher->push();
        } catch (Throwable $e) {
            $warnings[] = __('The mail server was not updated: :error', ['error' => $e->getMessage()]);
        }
        if ($syncDns && $domain->dns_managed && $this->config->hasCloudflare()) {
            try {
                $plan = $this->dns->apply($domain->fresh());
                if ($plan->conflicts) {
                    $warnings[] = __('Some DNS records already exist with other values. Check the DNS tab.');
                }
            } catch (Throwable $e) {
                $warnings[] = __('DNS was not updated: :error', ['error' => $e->getMessage()]);
            }
        }

        return $warnings;
    }

    public function ensureDkim(MailDomain $domain, bool $replace = false): void
    {
        try {
            $data = $this->agent->call('mail.dkim_generate', ['domain' => $domain->domain, 'replace' => $replace], 90);
        } catch (AgentException $e) {
            if ($replace || $e->errorCode !== 'conflict') {
                throw $e;
            }
            $data = $this->agent->call('mail.dkim_get', ['domain' => $domain->domain]);
        }
        $domain->update(['dkim_public_key' => (string) $data['public_key']]);
    }
}
