<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\SyncChange;
use Rhis\DnsMail\Dns\SyncPlanner;
use Rhis\DnsMail\Mail\DkimKey;
use Rhis\DnsMail\Mail\DmarcPolicy;
use Rhis\DnsMail\Mail\DomainMailSettings;
use Rhis\DnsMail\Mail\MailDnsProfile;
use Rhis\DnsMail\Mail\MailProvider;
use Rhis\DnsMail\Mail\MailServerSettings;
use Rhis\DnsMail\Mail\MtaSts;
use Rhis\DnsMail\Mail\SpfRecord;

final class MailTest extends TestCase
{
    private function profile(): MailDnsProfile
    {
        return new MailDnsProfile(new MailServerSettings('mail.rhis.nl', '192.0.2.10', '2001:db8::10', webmailHost: 'webmail.rhis.nl'));
    }

    /** @param list<DnsRecord> $records */
    private static function find(array $records, string $type, string $name): array
    {
        return array_values(array_filter($records, static fn (DnsRecord $r): bool => $r->type === $type && $r->name === $name));
    }

    public function testRhisProfile(): void
    {
        $records = $this->profile()->records(new DomainMailSettings(
            'Klant.nl', MailProvider::Rhis, dkim: new DkimKey('whp', 'QUJD'), tlsRptAddress: 'tls@rhis.nl',
        ));
        self::assertSame('mail.rhis.nl', self::find($records, 'MX', 'klant.nl')[0]->content);
        $txt = array_map(static fn (DnsRecord $r): string => $r->content, self::find($records, 'TXT', 'klant.nl'));
        self::assertSame(['v=spf1 mx ip4:192.0.2.10 ip6:2001:db8::10 ~all'], $txt);
        self::assertSame('v=DKIM1; k=rsa; p=QUJD', self::find($records, 'TXT', 'whp._domainkey.klant.nl')[0]->content);
        self::assertStringStartsWith('v=DMARC1; p=quarantine', self::find($records, 'TXT', '_dmarc.klant.nl')[0]->content);
        self::assertCount(1, self::find($records, 'SRV', '_imaps._tcp.klant.nl'));
        self::assertCount(1, self::find($records, 'CNAME', 'autoconfig.klant.nl'));
        self::assertStringStartsWith('v=STSv1; id=', self::find($records, 'TXT', '_mta-sts.klant.nl')[0]->content);
        self::assertSame('v=TLSRPTv1; rua=mailto:tls@rhis.nl', self::find($records, 'TXT', '_smtp._tls.klant.nl')[0]->content);
    }

    public function testMicrosoft365Profile(): void
    {
        $records = $this->profile()->records(new DomainMailSettings(
            'klant.nl', MailProvider::Microsoft365, dkim: new DkimKey('whp', 'QUJD'), m365Tenant: 'klant', m365Verification: 'MS=ms123',
        ));
        self::assertSame('klant-nl.mail.protection.outlook.com', self::find($records, 'MX', 'klant.nl')[0]->content);
        $txt = array_map(static fn (DnsRecord $r): string => $r->content, self::find($records, 'TXT', 'klant.nl'));
        self::assertContains('v=spf1 include:spf.protection.outlook.com ip4:192.0.2.10 ip6:2001:db8::10 ~all', $txt);
        self::assertContains('MS=ms123', $txt);
        self::assertSame('autodiscover.outlook.com', self::find($records, 'CNAME', 'autodiscover.klant.nl')[0]->content);
        self::assertSame('selector1-klant-nl._domainkey.klant.onmicrosoft.com', self::find($records, 'CNAME', 'selector1._domainkey.klant.nl')[0]->content);
        self::assertCount(1, self::find($records, 'TXT', 'whp._domainkey.klant.nl'));
        self::assertSame([], self::find($records, 'SRV', '_imaps._tcp.klant.nl'));
    }

    public function testSwitchingProviderRemovesOldManagedRecords(): void
    {
        $profile = $this->profile();
        $planner = new SyncPlanner();
        $rhis = $profile->records(new DomainMailSettings('klant.nl', MailProvider::Rhis));
        // Pretend Cloudflare has the RHIS records, created by the panel.
        $existing = array_map(static fn (DnsRecord $r): DnsRecord => $r->withManagedComment()->with(id: substr(sha1($r->fingerprint()), 0, 8)), $rhis);

        $plan = $planner->plan('klant.nl', $profile->records(new DomainMailSettings('klant.nl', MailProvider::Microsoft365)), $existing);

        self::assertSame([], $plan->conflicts);
        $deleted = array_map(static fn (SyncChange $c): string => $c->record->name, $plan->ofAction(SyncChange::DELETE));
        self::assertContains('_imaps._tcp.klant.nl', $deleted);
        self::assertContains('autoconfig.klant.nl', $deleted);
        $updated = array_map(static fn (SyncChange $c): string => $c->record->type . ' ' . $c->record->content, $plan->ofAction(SyncChange::UPDATE));
        self::assertContains('MX klant-nl.mail.protection.outlook.com', $updated);
        self::assertContains('CNAME autodiscover.outlook.com', $updated);
    }

    public function testExternalOnlyKeepsOwnDkim(): void
    {
        $records = $this->profile()->records(new DomainMailSettings('klant.nl', MailProvider::External, dkim: new DkimKey('whp', 'QUJD')));
        self::assertCount(1, $records);
        self::assertSame('whp._domainkey.klant.nl', $records[0]->name);
    }

    public function testSpfGeneratorAndParser(): void
    {
        $spf = SpfRecord::parse('v=spf1 include:_spf.google.com include:mailchimp.com mx -all')->withInclude('spf.protection.outlook.com');
        self::assertSame('v=spf1 include:_spf.google.com include:mailchimp.com mx include:spf.protection.outlook.com -all', $spf->toString());
        self::assertSame(4, $spf->lookups());
        self::assertSame([], $spf->warnings());

        $many = new SpfRecord();
        for ($i = 0; $i < 11; $i++) {
            $many = $many->withInclude("s{$i}.example.com");
        }
        self::assertContains('dns-mail::dns-mail.spf.too_many_lookups', $many->warnings());
        self::assertContains('dns-mail::dns-mail.spf.pass_all', (new SpfRecord(all: '+'))->warnings());
        self::assertSame('v=spf1 ip4:192.0.2.0/24 -all', (new SpfRecord(all: '-'))->withIp('192.0.2.0/24')->toString());

        $this->expectException(InvalidArgumentException::class);
        (new SpfRecord())->withInclude('not a host');
    }

    public function testDmarcGeneratorAndParser(): void
    {
        $d = new DmarcPolicy('reject', 'quarantine', 50, ['dmarc@rhis.nl'], [], true);
        self::assertSame('v=DMARC1; p=reject; sp=quarantine; pct=50; rua=mailto:dmarc@rhis.nl; adkim=s', $d->toString());
        self::assertSame($d->toString(), DmarcPolicy::parse($d->toString())->toString());
        self::assertSame(['rhis.nl'], $d->externalReportDomains('klant.nl'));
        self::assertSame([], $d->externalReportDomains('rhis.nl'));

        $this->expectException(InvalidArgumentException::class);
        new DmarcPolicy('block');
    }

    public function testDkimFromPem(): void
    {
        $key = DkimKey::fromPem('whp', "-----BEGIN PUBLIC KEY-----\nQUJD\nREVG\n-----END PUBLIC KEY-----\n");
        self::assertSame('QUJDREVG', $key->publicKey);
    }

    public function testMtaStsPolicy(): void
    {
        $sts = new MtaSts(['mail.rhis.nl'], 'enforce');
        self::assertSame("version: STSv1\r\nmode: enforce\r\nmx: mail.rhis.nl\r\nmax_age: 604800\r\n", $sts->policy());
        self::assertNotSame($sts->id(), (new MtaSts(['mail.rhis.nl'], 'testing'))->id());
    }
}
