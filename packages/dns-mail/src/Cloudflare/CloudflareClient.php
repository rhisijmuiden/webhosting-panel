<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Cloudflare;

use JsonException;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Http\HttpClient;

/**
 * Thin client for the Cloudflare v4 API, limited to what the panel needs:
 * verify a token, find zones and read/write DNS records.
 *
 * The token needs Zone:Read and DNS:Edit on the zones the panel manages.
 */
final class CloudflareClient
{
    public const string BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter] private readonly string $apiToken,
        private readonly string $baseUrl = self::BASE_URL,
    ) {
    }

    /** @return array{id: string, status: string} */
    public function verifyToken(): array
    {
        $result = $this->call('GET', '/user/tokens/verify');

        return ['id' => (string) ($result['id'] ?? ''), 'status' => (string) ($result['status'] ?? '')];
    }

    /** @return list<array{id: string, name: string, status: string}> */
    public function listZones(): array
    {
        $zones = [];
        foreach ($this->paginate('/zones', ['per_page' => 50]) as $z) {
            $zones[] = ['id' => (string) $z['id'], 'name' => (string) $z['name'], 'status' => (string) ($z['status'] ?? '')];
        }

        return $zones;
    }

    /** Zone for a domain, or null when the domain is not in this Cloudflare account. */
    public function findZone(string $domain): ?array
    {
        $domain = DnsRecord::normaliseName($domain);
        // Walk up so "shop.example.com" finds the "example.com" zone.
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            $name = implode('.', $labels);
            $result = $this->call('GET', '/zones', ['name' => $name]);
            if (isset($result[0]['id'])) {
                return ['id' => (string) $result[0]['id'], 'name' => (string) $result[0]['name'], 'status' => (string) ($result[0]['status'] ?? '')];
            }
            array_shift($labels);
        }

        return null;
    }

    /** @return list<DnsRecord> */
    public function listRecords(string $zoneId): array
    {
        $records = [];
        foreach ($this->paginate('/zones/' . rawurlencode($zoneId) . '/dns_records', ['per_page' => 500]) as $r) {
            if (in_array(strtoupper((string) ($r['type'] ?? '')), DnsRecord::SUPPORTED_TYPES, true)) {
                $records[] = DnsRecord::fromCloudflare($r);
            }
        }

        return $records;
    }

    public function createRecord(string $zoneId, DnsRecord $record): DnsRecord
    {
        return DnsRecord::fromCloudflare($this->call('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', [], $record->toCloudflare()));
    }

    public function updateRecord(string $zoneId, string $recordId, DnsRecord $record): DnsRecord
    {
        $path = '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId);

        return DnsRecord::fromCloudflare($this->call('PUT', $path, [], $record->toCloudflare()));
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->call('DELETE', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId));
    }

    /**
     * Apply creates, updates and deletes in one atomic request. Cloudflare
     * runs deletes, then patches, then puts, then posts; if one fails, none
     * are applied.
     *
     * @param list<DnsRecord> $creates
     * @param list<DnsRecord> $updates records with an id
     * @param list<string> $deleteIds
     */
    public function batch(string $zoneId, array $creates, array $updates, array $deleteIds): void
    {
        if ($creates === [] && $updates === [] && $deleteIds === []) {
            return;
        }
        $this->call('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records/batch', [], [
            'deletes' => array_map(static fn (string $id): array => ['id' => $id], $deleteIds),
            'puts' => array_map(static fn (DnsRecord $r): array => ['id' => $r->id] + $r->toCloudflare(), $updates),
            'posts' => array_map(static fn (DnsRecord $r): array => $r->toCloudflare(), $creates),
        ]);
    }

    /**
     * @param array<string, scalar> $query
     * @return iterable<array<mixed>>
     */
    private function paginate(string $path, array $query): iterable
    {
        $page = 1;
        do {
            $payload = $this->raw('GET', $path, $query + ['page' => $page]);
            foreach ($payload['result'] ?? [] as $item) {
                yield $item;
            }
            $totalPages = (int) ($payload['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $result = $this->raw($method, $path, $query, $body)['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function raw(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization' => 'Bearer ' . $this->apiToken, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->http->request($method, $url, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        try {
            $payload = $response->json();
        } catch (JsonException) {
            throw new CloudflareException('Cloudflare returned an invalid response (HTTP ' . $response->status . ')', $response->status);
        }
        if ($response->status >= 400 || ($payload['success'] ?? false) !== true) {
            throw CloudflareException::fromResponse($response->status, $payload);
        }

        return $payload;
    }
}
