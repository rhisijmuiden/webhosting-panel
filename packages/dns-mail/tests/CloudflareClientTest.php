<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\Cloudflare\CloudflareClient;
use Rhis\DnsMail\Cloudflare\CloudflareException;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\WebRecords;
use Rhis\DnsMail\Dns\ZoneSync;

final class CloudflareClientTest extends TestCase
{
    public function testFindZoneWalksUpToTheRegisteredDomain(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, ['success' => true, 'result' => []]);
        $http->queue(200, ['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com', 'status' => 'active']]]);
        $zone = (new CloudflareClient($http, 'tok'))->findZone('shop.example.com');
        self::assertSame('z1', $zone['id']);
        self::assertStringContainsString('name=shop.example.com', $http->requests[0]['url']);
        self::assertSame('Bearer tok', $http->requests[0]['headers']['Authorization']);
    }

    public function testListRecordsFollowsPagesAndSkipsUnsupportedTypes(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, ['success' => true, 'result' => [['id' => 'r1', 'type' => 'A', 'name' => 'example.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => true]], 'result_info' => ['total_pages' => 2]]);
        $http->queue(200, ['success' => true, 'result' => [['id' => 'r2', 'type' => 'NS', 'name' => 'example.com', 'content' => 'x'], ['id' => 'r3', 'type' => 'TXT', 'name' => 'example.com', 'content' => '"hello"', 'comment' => 'managed:rhis-panel']], 'result_info' => ['total_pages' => 2]]);
        $records = (new CloudflareClient($http, 'tok'))->listRecords('z1');
        self::assertCount(2, $records);
        self::assertSame('hello', $records[1]->content);
        self::assertTrue($records[1]->isManaged());
        self::assertStringContainsString('page=2', $http->requests[1]['url']);
    }

    public function testErrorsBecomeExceptions(): void
    {
        $http = new FakeHttpClient();
        $http->queue(403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]);
        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('10000: Authentication error');
        (new CloudflareClient($http, 'bad'))->verifyToken();
    }

    public function testZoneSyncAppliesOneBatch(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, ['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com']]]);
        $http->queue(200, ['success' => true, 'result' => [
            ['id' => 'old', 'type' => 'A', 'name' => 'www.example.com', 'content' => '198.51.100.1', 'ttl' => 1, 'proxied' => true, 'comment' => 'managed:rhis-panel web'],
        ]]);
        $http->queue(200, ['success' => true, 'result' => []]);

        $plan = (new ZoneSync(new CloudflareClient($http, 'tok')))->apply('example.com', WebRecords::forSite('example.com', '192.0.2.1', null), note: 'web');

        self::assertCount(3, $http->requests);
        self::assertStringEndsWith('/zones/z1/dns_records/batch', $http->requests[2]['url']);
        $body = json_decode((string) $http->requests[2]['body'], true);
        self::assertCount(1, $body['posts']);
        self::assertSame('example.com', $body['posts'][0]['name']);
        self::assertSame('old', $body['puts'][0]['id']);
        self::assertSame('192.0.2.1', $body['puts'][0]['content']);
        self::assertSame('managed:rhis-panel web', $body['puts'][0]['comment']);
        self::assertSame([], $body['deletes']);
        self::assertCount(2, $plan->changes);
    }
}
