<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Cloudflare;

use JsonException;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Http\HttpClient;

/**
 * Thin client for the Cloudflare v4 API, limited to what the panel needs:
 * verify a token, find and add zones and read/write DNS records.
 *
 * The token needs Zone:Read and DNS:Edit on the zones the panel manages,
 * and Zone:Edit on the account to add new zones (DNS onboarding wizard).
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

    /** @return list<array{id: string, name: string}> accounts the token can see */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->paginate('/accounts', ['per_page' => 50]) as $a) {
            $accounts[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? '')];
        }

        return $accounts;
    }

    /**
     * Add a domain to the account as a full (nameserver) zone. Cloudflare
     * assigns two nameservers; the zone stays "pending" until the registrar
     * points the domain at them. Needs Zone:Edit on the account.
     *
     * @return array{id: string, name: string, status: string, name_servers: list<string>, original_name_servers: list<string>, original_registrar: ?string}
     */
    public function createZone(string $domain, string $accountId): array
    {
        return self::zone($this->call('POST', '/zones', [], [
            'name' => DnsRecord::normaliseName($domain),
            'account' => ['id' => $accountId],
            'type' => 'full',
        ]));
    }

    /** @return array{id: string, name: string, status: string, name_servers: list<string>, original_name_servers: list<string>, original_registrar: ?string} */
    public function getZone(string $zoneId): array
    {
        return self::zone($this->call('GET', '/zones/' . rawurlencode($zoneId)));
    }

    /**
     * Ask Cloudflare to look at the nameservers again now instead of on its
     * own schedule. Cloudflare limits how often this may be called; a
     * refusal is not an error for the caller.
     */
    public function activationCheck(string $zoneId): bool
    {
        try {
            $this->call('PUT', '/zones/' . rawurlencode($zoneId) . '/activation_check');

            return true;
        } catch (CloudflareException) {
            return false;
        }
    }

    /**
     * Let Cloudflare copy the records it finds at the current DNS provider
     * (common names only). Returns how many it added.
     */
    public function scanRecords(string $zoneId): int
    {
        $result = $this->call('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records/scan');

        return (int) ($result['recs_added'] ?? 0);
    }

    /** @param array<mixed> $z */
    private static function zone(array $z): array
    {
        $list = static fn ($v): array => array_values(array_map(
            static fn ($n): string => DnsRecord::normaliseName((string) $n),
            is_array($v) ? $v : [],
        ));

        return [
            'id' => (string) ($z['id'] ?? ''),
            'name' => (string) ($z['name'] ?? ''),
            'status' => (string) ($z['status'] ?? ''),
            'name_servers' => $list($z['name_servers'] ?? []),
            'original_name_servers' => $list($z['original_name_servers'] ?? []),
            'original_registrar' => isset($z['original_registrar']) && $z['original_registrar'] !== '' ? (string) $z['original_registrar'] : null,
        ];
    }

    /** DNSSEC status of a zone: "active", "pending", "disabled", ... (DANE needs "active"). */
    public function dnssecStatus(string $zoneId): string
    {
        return (string) ($this->call('GET', '/zones/' . rawurlencode($zoneId) . '/dnssec')['status'] ?? 'disabled');
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
