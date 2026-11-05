<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Dns\DnsRecord;

final class DnsRecordTest extends TestCase
{
    public function testNamesAndHostsAreNormalised(): void
    {
        $r = new DnsRecord('mx', 'Example.COM.', 'Mail.Example.com.', priority: 10);
        self::assertSame('MX', $r->type);
        self::assertSame('example.com', $r->name);
        self::assertSame('mail.example.com', $r->content);
        self::assertSame('@', $r->relativeName('example.com'));
    }

    public function testQuotedTxtFromCloudflareEqualsPlainTxt(): void
    {
        $a = new DnsRecord('TXT', 'example.com', '"v=spf1 mx " "-all"');
        $b = new DnsRecord('TXT', 'example.com', 'v=spf1 mx -all');
        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testLongTxtIsSplitInChunks(): void
    {
        $key = str_repeat('A', 400);
        $r = new DnsRecord('TXT', 'whp._domainkey.example.com', 'v=DKIM1; k=rsa; p=' . $key);
        $wire = $r->toCloudflare()['content'];
        self::assertStringStartsWith('"', $wire);
        self::assertSame($r->content, DnsRecord::unquoteTxt($wire));
    }

    public function testSrvPayloadUsesData(): void
    {
        $r = new DnsRecord('SRV', '_imaps._tcp.example.com', '', srv: ['priority' => 0, 'weight' => 1, 'port' => 993, 'target' => 'mail.example.com']);
        $p = $r->toCloudflare();
        self::assertSame(993, $p['data']['port']);
        self::assertArrayNotHasKey('content', $p);
        $back = DnsRecord::fromCloudflare(['type' => 'SRV', 'name' => '_imaps._tcp.example.com', 'content' => '1 993 mail.example.com', 'priority' => 0, 'data' => $p['data'], 'ttl' => 1]);
        self::assertSame($r->fingerprint(), $back->fingerprint());
    }

    public function testManagedMarker(): void
    {
        $r = (new DnsRecord('A', 'example.com', '192.0.2.1'))->withManagedComment('web');
        self::assertTrue($r->isManaged());
        self::assertSame('managed:rhis-panel web', $r->comment);
        self::assertFalse((new DnsRecord('A', 'example.com', '192.0.2.1', comment: 'mine'))->isManaged());
    }
}
