# rhis/dns-mail

Cloudflare DNS sync, per-domain mail routing (RHIS mail or Microsoft 365),
SPF/DMARC/DKIM/MTA-STS records and mail client setup for Dekwerk.
See [docs/dns-mail-webmail.md](../../docs/dns-mail-webmail.md).

```php
$profile = new MailDnsProfile(new MailServerSettings('mail.rhis.nl', '192.0.2.10'));
$records = [
    ...WebRecords::forSite('klant.nl', '192.0.2.10', null),
    ...$profile->records(new DomainMailSettings('klant.nl', MailProvider::Rhis, dkim: $key)),
];
$plan = (new ZoneSync(new CloudflareClient(new CurlHttpClient(), $token)))->plan('klant.nl', $records);
```

Tests: `composer install && vendor/bin/phpunit`.
