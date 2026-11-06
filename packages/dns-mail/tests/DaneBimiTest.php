<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordScope;
use Rhis\DnsMail\Dns\SyncPlanner;
use Rhis\DnsMail\Mail\Bimi;
use Rhis\DnsMail\Mail\DmarcPolicy;
use Rhis\DnsMail\Mail\DomainMailSettings;
use Rhis\DnsMail\Mail\MailDnsProfile;
use Rhis\DnsMail\Mail\MailProvider;
use Rhis\DnsMail\Mail\MailServerSettings;

final class DaneBimiTest extends TestCase
{
    public function testTlsaRoundTripsThroughCloudflare(): void
    {
        $r = new DnsRecord('TLSA', '_25._tcp.Mail.rhis.nl.', '3 1 1 AB CD');
        $this->assertSame('3 1 1 abcd', $r->content);
        $payload = $r->toCloudflare();
        $this->assertSame(['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => 'abcd'], $payload['data']);
        $back = DnsRecord::fromCloudflare(['type' => 'TLSA', 'name' => '_25._tcp.mail.rhis.nl', 'data' => $payload['data'], 'content' => '3 1 1 ABCD']);
        $this->assertSame($r->fingerprint(), $back->fingerprint());
        $this->assertSame('_25._tcp.mail.rhis.nl|tlsa', SyncPlanner::slotOf($r));
        $this->expectException(InvalidArgumentException::class);
        new DnsRecord('TLSA', 'x.nl', '3 1 1 not-hex');
    }

    public function testBimiNeedsEnforcedDmarc(): void
    {
        $profile = new MailDnsProfile(new MailServerSettings('mail.rhis.nl'));
        $bimi = new Bimi('https://klant.nl/logo.svg', 'https://klant.nl/vmc.pem');
        $find = fn (array $records) => array_values(array_filter($records, fn (DnsRecord $r) => $r->name === 'default._bimi.klant.nl'));

        $none = new DomainMailSettings('klant.nl', MailProvider::Rhis, dmarc: new DmarcPolicy('none'), bimi: $bimi);
        $this->assertSame([], $find($profile->records($none)));

        $reject = new DomainMailSettings('klant.nl', MailProvider::Microsoft365, dmarc: new DmarcPolicy('reject'), bimi: $bimi);
        $rec = $find($profile->records($reject));
        $this->assertSame('v=BIMI1; l=https://klant.nl/logo.svg; a=https://klant.nl/vmc.pem', $rec[0]->content);
        $this->assertStringEndsWith('|bimi', SyncPlanner::slotOf($rec[0]));
        $this->assertFalse(Bimi::dmarcAllows(new DmarcPolicy('quarantine', percentage: 50)));

        $this->expectException(InvalidArgumentException::class);
        new Bimi('http://klant.nl/logo.svg');
    }

    public function testScopeKeepsOtherDomainsAndServerRecordsOut(): void
    {
        $managed = DnsRecord::MANAGED_MARKER . ' rhis';
        $records = [
            new DnsRecord('MX', 'klant.nl', 'mail.rhis.nl', priority: 10, comment: $managed),
            new DnsRecord('MX', 'shop.klant.nl', 'mail.rhis.nl', priority: 10, comment: $managed),
            new DnsRecord('TLSA', '_25._tcp.mail.klant.nl', '3 1 1 ab', comment: DnsRecord::MANAGED_MARKER . ' server'),
            new DnsRecord('A', 'www.klant.nl', '192.0.2.1'),
            new DnsRecord('A', 'notklant.nl', '192.0.2.1'),
        ];
        $names = array_map(fn ($r) => $r->name, RecordScope::forDomain('klant.nl', $records, ['shop.klant.nl', 'klant.nl', 'other.nl']));
        $this->assertSame(['klant.nl', 'www.klant.nl'], $names);
        $this->assertCount(1, RecordScope::atNames($records, ['_25._tcp.mail.klant.nl'], 'TLSA'));
    }
}
