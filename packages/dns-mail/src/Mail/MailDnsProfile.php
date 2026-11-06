<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use Rhis\DnsMail\Dns\DnsRecord;

/**
 * The DNS records a domain needs for its chosen mail provider.
 *
 * Feed the result to SyncPlanner together with the web records: when the
 * provider switches, the old provider's managed records drop out of the
 * desired set and are deleted, the new ones are created.
 */
final class MailDnsProfile
{
    public function __construct(private readonly MailServerSettings $server)
    {
    }

    /** @return list<DnsRecord> */
    public function records(DomainMailSettings $s): array
    {
        return match ($s->provider) {
            MailProvider::Rhis => [...$this->rhis($s), ...$this->transportSecurity($s)],
            MailProvider::Microsoft365 => [...$this->microsoft365($s), ...$this->transportSecurity($s)],
            MailProvider::External => $this->external($s),
        };
    }

    public function spf(DomainMailSettings $s): SpfRecord
    {
        $spf = new SpfRecord(all: $s->spfAll);
        if ($s->provider === MailProvider::Rhis) {
            $spf = $spf->withMx();
        }
        if ($s->provider === MailProvider::Microsoft365) {
            $spf = $spf->withInclude('spf.protection.outlook.com');
        }
        if ($s->provider === MailProvider::Rhis || $s->serverSends) {
            $spf = $spf->merge($this->server->spf());
        }
        if ($s->extraSpf !== null) {
            $spf = $spf->merge($s->extraSpf);
        }

        return $spf;
    }

    /** MTA-STS policy for the domain, or null when it is off or not applicable. */
    public function mtaSts(DomainMailSettings $s): ?MtaSts
    {
        if ($s->mtaStsMode === null) {
            return null;
        }

        return match ($s->provider) {
            MailProvider::Rhis => new MtaSts([$this->server->mailHost], $s->mtaStsMode),
            MailProvider::Microsoft365 => new MtaSts(['*.mail.protection.outlook.com'], $s->mtaStsMode),
            MailProvider::External => null,
        };
    }

    /** @return list<DnsRecord> */
    private function transportSecurity(DomainMailSettings $s): array
    {
        $d = $s->domain();
        $records = [];
        $sts = $this->mtaSts($s);
        if ($sts !== null) {
            $records[] = new DnsRecord('TXT', '_mta-sts.' . $d, $sts->txt());
            // The policy host is served by this server, also for Microsoft 365 domains.
            $records[] = new DnsRecord('CNAME', 'mta-sts.' . $d, $this->server->mailHost, proxied: false);
        }
        if ($s->tlsRptAddress !== null && $s->provider !== MailProvider::External) {
            $records[] = new DnsRecord('TXT', '_smtp._tls.' . $d, MtaSts::tlsRptTxt($s->tlsRptAddress));
        }
        if ($s->bimi !== null && Bimi::dmarcAllows($s->dmarc)) {
            $records[] = new DnsRecord('TXT', $s->bimi->recordName($d), $s->bimi->txt());
        }

        return $records;
    }

    /** @return list<DnsRecord> */
    private function rhis(DomainMailSettings $s): array
    {
        $d = $s->domain();
        $host = $this->server->mailHost;
        $records = [
            new DnsRecord('MX', $d, $host, priority: 10),
            new DnsRecord('TXT', $d, $this->spf($s)->toString()),
            new DnsRecord('TXT', '_dmarc.' . $d, $s->dmarc->toString()),
        ];
        if ($s->dkim !== null) {
            $records[] = new DnsRecord('TXT', $s->dkim->recordName($d), $s->dkim->txt());
        }
        if ($s->clientSetupRecords) {
            array_push($records, ...$this->clientSetup($d, $host));
        }
        if ($this->server->webmailHost !== null) {
            $records[] = new DnsRecord('CNAME', 'webmail.' . $d, $this->server->webmailHost, proxied: false);
        }

        return $records;
    }

    /** @return list<DnsRecord> */
    private function microsoft365(DomainMailSettings $s): array
    {
        $d = $s->domain();
        $dashed = str_replace('.', '-', $d);
        $records = [
            new DnsRecord('MX', $d, $s->m365MxHost ?? $dashed . '.mail.protection.outlook.com', priority: 0),
            new DnsRecord('TXT', $d, $this->spf($s)->toString()),
            new DnsRecord('TXT', '_dmarc.' . $d, $s->dmarc->toString()),
            new DnsRecord('CNAME', 'autodiscover.' . $d, 'autodiscover.outlook.com', proxied: false),
        ];
        if ($s->m365Verification !== null && $s->m365Verification !== '') {
            $records[] = new DnsRecord('TXT', $d, $s->m365Verification);
        }
        if ($s->m365Tenant !== null && $s->m365Tenant !== '') {
            $tenant = str_ends_with($s->m365Tenant, '.onmicrosoft.com') ? $s->m365Tenant : $s->m365Tenant . '.onmicrosoft.com';
            foreach (['selector1', 'selector2'] as $sel) {
                $target = $s->m365DkimTargets[$sel] ?? "{$sel}-{$dashed}._domainkey.{$tenant}";
                $records[] = new DnsRecord('CNAME', "{$sel}._domainkey.{$d}", $target, proxied: false);
            }
        }
        // Website mail from this server is signed with our own selector next to Microsoft's.
        if ($s->serverSends && $s->dkim !== null) {
            $records[] = new DnsRecord('TXT', $s->dkim->recordName($d), $s->dkim->txt());
        }
        if ($s->m365Intune) {
            $records[] = new DnsRecord('CNAME', 'enterpriseregistration.' . $d, 'enterpriseregistration.windows.net', proxied: false);
            $records[] = new DnsRecord('CNAME', 'enterpriseenrollment.' . $d, 'enterpriseenrollment.manage.microsoft.com', proxied: false);
        }

        return $records;
    }

    /** External: only our own DKIM key when websites here send mail; SPF/MX stay with the other provider. */
    private function external(DomainMailSettings $s): array
    {
        if ($s->serverSends && $s->dkim !== null) {
            return [new DnsRecord('TXT', $s->dkim->recordName($s->domain()), $s->dkim->txt())];
        }

        return [];
    }

    /**
     * Records mail apps use to find the server by themselves (RFC 6186, RFC 8314,
     * Thunderbird autoconfig, Outlook autodiscover).
     *
     * @return list<DnsRecord>
     */
    private function clientSetup(string $d, string $host): array
    {
        return [
            new DnsRecord('CNAME', 'autoconfig.' . $d, $host, proxied: false),
            new DnsRecord('CNAME', 'autodiscover.' . $d, $host, proxied: false),
            new DnsRecord('SRV', '_imaps._tcp.' . $d, '', srv: ['priority' => 0, 'weight' => 1, 'port' => 993, 'target' => $host]),
            new DnsRecord('SRV', '_submissions._tcp.' . $d, '', srv: ['priority' => 0, 'weight' => 1, 'port' => 465, 'target' => $host]),
            new DnsRecord('SRV', '_submission._tcp.' . $d, '', srv: ['priority' => 10, 'weight' => 1, 'port' => 587, 'target' => $host]),
            new DnsRecord('SRV', '_autodiscover._tcp.' . $d, '', srv: ['priority' => 0, 'weight' => 1, 'port' => 443, 'target' => $host]),
            // Plain IMAP/POP3 are not offered: "." target means "not available" (RFC 6186 3.4).
            new DnsRecord('SRV', '_imap._tcp.' . $d, '', srv: ['priority' => 0, 'weight' => 0, 'port' => 0, 'target' => '.']),
            new DnsRecord('SRV', '_pop3._tcp.' . $d, '', srv: ['priority' => 0, 'weight' => 0, 'port' => 0, 'target' => '.']),
        ];
    }
}
