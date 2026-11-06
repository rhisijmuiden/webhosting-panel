<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\WebRecords;
use Rhis\DnsMail\Mail\Bimi;
use Rhis\DnsMail\Mail\DkimKey;
use Rhis\DnsMail\Mail\DmarcPolicy;
use Rhis\DnsMail\Mail\DomainMailSettings;
use Rhis\DnsMail\Mail\MailDnsProfile;
use Rhis\DnsMail\Mail\SpfRecord;
use Rhis\DnsMail\Models\MailDomain;
use Throwable;

/** Turns a MailDomain row into the records it should have in DNS. */
final class DomainRecords
{
    public function __construct(private readonly DnsMailConfig $config)
    {
    }

    public function settings(MailDomain $d): DomainMailSettings
    {
        $rua = $d->dmarc_rua ?: $this->config->get('dmarc_rua');
        $extra = null;
        if ($d->spf_extra) {
            try {
                $extra = SpfRecord::parse('v=spf1 ' . $d->spf_extra);
            } catch (Throwable) {
                $extra = null;
            }
        }

        return new DomainMailSettings(
            domain: $d->domain,
            provider: $d->providerEnum(),
            dkim: $d->dkim_public_key ? new DkimKey($this->config->server()->dkimSelector, $d->dkim_public_key) : null,
            dmarc: new DmarcPolicy($d->dmarc_policy ?: 'quarantine', aggregateReports: $rua ? [$rua] : []),
            extraSpf: $extra,
            spfAll: $d->spf_all ?: '~',
            serverSends: $d->server_sends,
            m365Tenant: $d->m365_tenant ?: null,
            m365MxHost: $d->m365_mx_host ?: null,
            m365DkimTargets: array_filter(['selector1' => $d->m365_dkim_selector1, 'selector2' => $d->m365_dkim_selector2]),
            m365Verification: $d->m365_verification ?: null,
            m365Intune: $d->m365_intune,
            mtaStsMode: $d->mta_sts_mode ?: null,
            tlsRptAddress: $this->config->get('tlsrpt_rua') ?: null,
            bimi: $this->bimi($d),
        );
    }

    private function bimi(MailDomain $d): ?Bimi
    {
        if (!$d->bimi_logo_url) {
            return null;
        }
        try {
            return new Bimi($d->bimi_logo_url, $d->bimi_vmc_url ?: null);
        } catch (Throwable) {
            return null;
        }
    }

    public function profile(): MailDnsProfile
    {
        return new MailDnsProfile($this->config->server());
    }

    /** @return list<DnsRecord> */
    public function mail(MailDomain $d): array
    {
        return $this->profile()->records($this->settings($d));
    }

    /** @return list<DnsRecord> */
    public function web(MailDomain $d): array
    {
        if ($d->website === null) {
            return [];
        }
        $server = $this->config->server();
        $aliases = array_values(array_filter(array_map(
            static fn (string $a): ?string => str_ends_with($a, '.' . $d->domain) ? substr($a, 0, -strlen($d->domain) - 1) : null,
            (array) $d->website->aliases,
        )));

        return WebRecords::forSite($d->domain, $server->ipv4, $server->ipv6, $d->proxied, $aliases ?: ['www']);
    }

    /** @return list<DnsRecord> */
    public function all(MailDomain $d): array
    {
        return [...$this->web($d), ...$this->mail($d)];
    }
}
