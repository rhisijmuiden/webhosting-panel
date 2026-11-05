<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

/** Mail choices for one domain, as set in the panel (admin, client portal or CRM API). */
final readonly class DomainMailSettings
{
    /**
     * @param SpfRecord|null $extraSpf the customer's other senders (newsletter tool, CRM), merged into the generated SPF
     * @param bool $serverSends websites on this server send mail as this domain (contact forms, WooCommerce)
     * @param string|null $m365Tenant initial domain of the tenant, e.g. "contoso" or "contoso.onmicrosoft.com"
     * @param string|null $m365MxHost MX host shown in the Microsoft 365 admin center; default is the classic <domain>.mail.protection.outlook.com
     * @param array{selector1?: string, selector2?: string} $m365DkimTargets CNAME targets from the Defender portal, when they differ from the classic format
     * @param string|null $m365Verification the "MS=ms12345678" TXT value from the admin center
     * @param bool $m365Intune add enterpriseregistration/enterpriseenrollment CNAMEs for device enrolment
     * @param string|null $mtaStsMode null = off, or testing / enforce / none (none withdraws a published policy)
     * @param string|null $tlsRptAddress where sending servers report TLS problems (TLS-RPT, RFC 8460)
     */
    public function __construct(
        public string $domain,
        public MailProvider $provider,
        public ?DkimKey $dkim = null,
        public DmarcPolicy $dmarc = new DmarcPolicy(),
        public ?SpfRecord $extraSpf = null,
        public string $spfAll = '~',
        public bool $serverSends = true,
        public bool $clientSetupRecords = true,
        public ?string $m365Tenant = null,
        public ?string $m365MxHost = null,
        public array $m365DkimTargets = [],
        public ?string $m365Verification = null,
        public bool $m365Intune = false,
        public ?string $mtaStsMode = 'testing',
        public ?string $tlsRptAddress = null,
    ) {
    }

    public function domain(): string
    {
        return rtrim(strtolower($this->domain), '.');
    }
}
