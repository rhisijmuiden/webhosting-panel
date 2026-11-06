<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Cloudflare\CloudflareClient;
use Rhis\DnsMail\Cloudflare\CloudflareException;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordCheck;
use Rhis\DnsMail\Mail\DmarcPolicy;
use Rhis\DnsMail\Mail\DomainMailSettings;
use Rhis\DnsMail\Mail\MailDnsProfile;
use Rhis\DnsMail\Mail\MailProvider;
use Rhis\DnsMail\Mail\MailServerSettings;
use Rhis\DnsMail\Testing\FakeResolver;

final class DnsOnboardingUnitTest extends TestCase
{
    public function testCreateZoneSendsAFullZoneAndReturnsTheNameservers(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, ['success' => true, 'result' => [
            'id' => 'z9', 'name' => 'klant.nl', 'status' => 'pending',
            'name_servers' => ['Ada.NS.Cloudflare.com.', 'bob.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.oldhost.nl'], 'original_registrar' => 'TransIP',
        ]]);
        $zone = (new CloudflareClient($http, 'tok'))->createZone('Klant.nl', 'acc1');

        self::assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $zone['name_servers']);
        self::assertSame('pending', $zone['status']);
        self::assertSame('TransIP', $zone['original_registrar']);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame(['name' => 'klant.nl', 'account' => ['id' => 'acc1'], 'type' => 'full'], json_decode($http->requests[0]['body'], true));
    }

    public function testActivationCheckRefusalIsNotAnError(): void
    {
        $http = new FakeHttpClient();
        $http->queue(429, ['success' => false, 'errors' => [['code' => 1224, 'message' => 'rate limited']]]);
        self::assertFalse((new CloudflareClient($http, 'tok'))->activationCheck('z1'));
    }

    public function testExceptionKnowsItsCodes(): void
    {
        $e = CloudflareException::fromResponse(400, ['errors' => [['code' => 1061, 'message' => 'klant.nl already exists']]]);
        self::assertTrue($e->hasCode(1061));
        self::assertFalse($e->hasCode(1049));
    }

    public function testMailNamesAreNeverProxied(): void
    {
        self::assertTrue(DnsRecord::mayProxy('A', 'klant.nl', 'klant.nl'));
        self::assertTrue(DnsRecord::mayProxy('CNAME', 'www.klant.nl', 'klant.nl'));
        self::assertTrue(DnsRecord::mayProxy('A', 'shop.klant.nl', 'klant.nl'));
        foreach (['mail', 'smtp', 'imap', 'autodiscover', 'autoconfig', 'mta-sts', 'webmail', 'ftp', '_acme-challenge'] as $label) {
            self::assertFalse(DnsRecord::mayProxy('A', $label . '.klant.nl', 'klant.nl'), $label);
        }
        self::assertFalse(DnsRecord::mayProxy('MX', 'klant.nl', 'klant.nl'));
        self::assertFalse(DnsRecord::mayProxy('TXT', 'klant.nl', 'klant.nl'));
    }

    public function testRhisMailDomainsGetMailHostRecordsThatAreDnsOnly(): void
    {
        $profile = new MailDnsProfile(new MailServerSettings('mail.rhis.nl', '192.0.2.10', '2001:db8::10'));
        $records = $profile->records(new DomainMailSettings('klant.nl', MailProvider::Rhis, null, new DmarcPolicy('quarantine')));
        $mail = array_values(array_filter($records, static fn (DnsRecord $r): bool => $r->name === 'mail.klant.nl'));

        self::assertSame(['A', 'AAAA'], array_map(static fn (DnsRecord $r): string => $r->type, $mail));
        self::assertSame([false, false], array_map(static fn (DnsRecord $r): ?bool => $r->proxied, $mail));

        // The server's own domain already has its mail host.
        $own = $profile->records(new DomainMailSettings('rhis.nl', MailProvider::Rhis, null, new DmarcPolicy('quarantine')));
        self::assertSame([], array_filter($own, static fn (DnsRecord $r): bool => $r->name === 'mail.rhis.nl'));
    }

    public function testRecordCheckReportsOkMissingAndWrong(): void
    {
        $dns = (new FakeResolver())->add(
            new DnsRecord('A', 'klant.nl', '192.0.2.10'),
            new DnsRecord('A', 'www.klant.nl', '192.0.2.10'),
            new DnsRecord('A', 'www.klant.nl', '203.0.113.9'),
            new DnsRecord('CNAME', 'autoconfig.klant.nl', 'MAIL.rhis.nl.'),
            new DnsRecord('MX', 'klant.nl', 'mail.rhis.nl', priority: 20),
            new DnsRecord('TXT', 'klant.nl', 'v=spf1 mx  ip4:192.0.2.10 ~all'),
            new DnsRecord('TXT', 'klant.nl', 'google-site-verification=abc'),
            new DnsRecord('TXT', '_dmarc.klant.nl', 'v=DMARC1; p=none'),
            new DnsRecord('A', 'webmail.klant.nl', '192.0.2.10'),
            new DnsRecord('SRV', '_imaps._tcp.klant.nl', '', srv: ['priority' => 0, 'weight' => 1, 'port' => 993, 'target' => 'mail.rhis.nl']),
        );
        $wanted = [
            new DnsRecord('A', 'klant.nl', '192.0.2.10', proxied: true),
            new DnsRecord('A', 'www.klant.nl', '192.0.2.10'),
            new DnsRecord('CNAME', 'autoconfig.klant.nl', 'mail.rhis.nl'),
            new DnsRecord('MX', 'klant.nl', 'mail.rhis.nl', priority: 10),
            new DnsRecord('TXT', 'klant.nl', 'v=spf1 mx ip4:192.0.2.10 ~all'),
            new DnsRecord('TXT', '_dmarc.klant.nl', 'v=DMARC1; p=quarantine'),
            new DnsRecord('TXT', 'whp._domainkey.klant.nl', 'v=DKIM1; k=rsa; p=abc'),
            new DnsRecord('CNAME', 'webmail.klant.nl', 'webmail.rhis.nl'),
            new DnsRecord('SRV', '_imaps._tcp.klant.nl', '', srv: ['priority' => 0, 'weight' => 1, 'port' => 993, 'target' => 'mail.rhis.nl']),
            new DnsRecord('TLSA', '_25._tcp.mail.klant.nl', '3 1 1 ab'),
        ];
        $results = (new RecordCheck($dns))->check($wanted);
        $status = array_map(static fn (array $r): string => $r['record']->type . ' ' . $r['record']->name . ' ' . $r['status'], $results);

        self::assertSame([
            'A klant.nl ok',
            'A www.klant.nl wrong',          // the old host's address is still there
            'CNAME autoconfig.klant.nl ok',
            'MX klant.nl wrong',             // priority 20 instead of 10
            'TXT klant.nl ok',               // whitespace does not matter
            'TXT _dmarc.klant.nl wrong',
            'TXT whp._domainkey.klant.nl missing',
            'CNAME webmail.klant.nl wrong',  // an A record instead of the CNAME
            'SRV _imaps._tcp.klant.nl ok',
        ], $status);
        self::assertSame('203.0.113.9', $results[1]['found'][0]->content);
        self::assertFalse(RecordCheck::allOk($results));
    }
}
