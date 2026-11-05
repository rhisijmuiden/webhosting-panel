<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\SyncChange;
use Rhis\DnsMail\Dns\SyncPlanner;

final class SyncPlannerTest extends TestCase
{
    private const string M = DnsRecord::MANAGED_MARKER;

    public function testEmptyZoneGetsEverythingCreated(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('A', 'example.com', '192.0.2.1', proxied: true),
            new DnsRecord('MX', 'example.com', 'mail.rhis.nl', priority: 10),
        ], []);
        self::assertCount(2, $plan->ofAction(SyncChange::CREATE));
        self::assertTrue($plan->ofAction(SyncChange::CREATE)[0]->record->isManaged());
    }

    public function testForeignRecordsInDesiredSlotAreAConflict(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('MX', 'example.com', 'mail.rhis.nl', priority: 10),
        ], [
            new DnsRecord('MX', 'example.com', 'old-host.example.net', priority: 10, id: 'x1'),
        ]);
        self::assertSame([], $plan->changes);
        self::assertCount(1, $plan->conflicts);
        self::assertSame('dns-mail::dns-mail.conflict.mx', $plan->conflicts[0]->messageKey());
    }

    public function testAdoptReplacesForeignRecordsInPlace(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('MX', 'example.com', 'mail.rhis.nl', priority: 10),
        ], [
            new DnsRecord('MX', 'example.com', 'old-host.example.net', priority: 10, id: 'x1'),
            new DnsRecord('MX', 'example.com', 'old-backup.example.net', priority: 20, id: 'x2'),
        ], adopt: true);
        self::assertCount(1, $plan->ofAction(SyncChange::UPDATE));
        self::assertSame('x1', $plan->ofAction(SyncChange::UPDATE)[0]->record->id);
        self::assertSame('x2', $plan->ofAction(SyncChange::DELETE)[0]->record->id);
        self::assertSame([], $plan->conflicts);
    }

    public function testForeignRecordWithRightValueIsLeftAlone(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('MX', 'example.com', 'mail.rhis.nl', priority: 10),
        ], [
            new DnsRecord('MX', 'example.com', 'mail.rhis.nl.', priority: 10, id: 'x1'),
        ]);
        self::assertTrue($plan->isInSync());
    }

    public function testRecordsOutsideDesiredSlotsAreNeverTouchedUnlessManaged(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [], [
            new DnsRecord('TXT', 'example.com', 'google-site-verification=abc', id: 'own'),
            new DnsRecord('CNAME', 'autoconfig.example.com', 'mail.rhis.nl', proxied: false, id: 'old', comment: self::M . ' mail:rhis'),
        ]);
        self::assertCount(1, $plan->changes);
        self::assertSame(SyncChange::DELETE, $plan->changes[0]->action);
        self::assertSame('old', $plan->changes[0]->record->id);
    }

    public function testManagedRecordWithOtherProxySettingIsUpdated(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('A', 'example.com', '192.0.2.1', proxied: true),
        ], [
            new DnsRecord('A', 'example.com', '192.0.2.1', proxied: false, id: 'a1', comment: self::M),
        ]);
        self::assertCount(1, $plan->ofAction(SyncChange::UPDATE));
        self::assertTrue($plan->ofAction(SyncChange::UPDATE)[0]->record->proxied);
    }

    public function testManagedInSyncZoneIsInSync(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('A', 'example.com', '192.0.2.1', proxied: true),
        ], [
            new DnsRecord('A', 'example.com', '192.0.2.1', proxied: true, id: 'a1', comment: self::M),
        ]);
        self::assertTrue($plan->isInSync());
        self::assertSame(1, $plan->toArray()['unchanged']);
    }

    public function testCnameAndAddressShareASlot(): void
    {
        $plan = (new SyncPlanner())->plan('example.com', [
            new DnsRecord('CNAME', 'webmail.example.com', 'mail.rhis.nl', proxied: false),
        ], [
            new DnsRecord('A', 'webmail.example.com', '198.51.100.7', id: 'w1'),
        ]);
        self::assertCount(1, $plan->conflicts);
        self::assertSame('dns-mail::dns-mail.conflict.address', $plan->conflicts[0]->messageKey());
    }
}
