<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Testing;

use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Http\HttpResponse;

/**
 * In-memory Cloudflare API for tests and local development: zones and DNS
 * records, token verify and the batch endpoint. Bind it to HttpClient.
 */
final class FakeCloudflare implements HttpClient
{
    /** @var array<string, array{id: string, name: string, records: array<string, array>}> */
    public array $zones = [];

    /** @var list<array{method: string, path: string, body: ?array}> */
    public array $requests = [];

    /** @var array<string, string> zone id => DNSSEC status ("active", "disabled", ...) */
    public array $dnssec = [];

    private int $seq = 0;

    /** @var list<array{id: string, name: string}> */
    public array $accounts = [['id' => 'acc1', 'name' => 'RHIS']];

    /** @var array<string, list<array>> records "found at the old provider" that a scan copies in, by domain */
    public array $scannable = [];

    /** Domains that exist in another Cloudflare account (create fails with 1061). */
    public array $taken = [];

    public int $activationChecks = 0;

    public function addZone(string $name, array $records = [], string $status = 'active'): string
    {
        $id = 'zone' . (++$this->seq);
        $this->zones[$id] = ['id' => $id, 'name' => $name, 'records' => [], 'status' => $status,
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['ns1.oldhost.nl', 'ns2.oldhost.nl'],
            'original_registrar' => 'Old Registrar B.V.'];
        foreach ($records as $r) {
            $this->store($id, $r);
        }

        return $id;
    }

    /** The registrar now points at Cloudflare: the zone becomes active. */
    public function activate(string $zoneName): void
    {
        foreach ($this->zones as $id => $z) {
            if ($z['name'] === $zoneName) {
                $this->zones[$id]['status'] = 'active';
            }
        }
    }

    public function zoneByName(string $name): ?array
    {
        foreach ($this->zones as $z) {
            if ($z['name'] === $name) {
                return $z;
            }
        }

        return null;
    }

    /** @return list<array> */
    public function records(string $zoneName): array
    {
        foreach ($this->zones as $z) {
            if ($z['name'] === $zoneName) {
                return array_values($z['records']);
            }
        }

        return [];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $parts = parse_url($url);
        $path = preg_replace('#^/client/v4#', '', $parts['path'] ?? '');
        parse_str($parts['query'] ?? '', $query);
        $data = $body !== null ? json_decode($body, true) : null;
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $data];

        if (($headers['Authorization'] ?? '') === 'Bearer bad') {
            return $this->error(403, 9109, 'Invalid access token');
        }

        // "Bearer acct": an account API token, unknown to the user endpoint.
        if (($headers['Authorization'] ?? '') === 'Bearer acct' && $path === '/user/tokens/verify') {
            return $this->error(401, 1000, 'Invalid API Token');
        }
        if ($method === 'GET' && preg_match('#^/accounts/[^/]+/tokens/verify$#', $path)) {
            return $this->ok(['id' => 'tok', 'status' => 'active']);
        }
        if ($method === 'GET' && $path === '/user/tokens/verify') {
            return $this->ok(['id' => 'tok', 'status' => 'active']);
        }
        if ($method === 'GET' && $path === '/zones') {
            $zones = array_values(array_filter($this->zones, fn ($z) => !isset($query['name']) || $z['name'] === $query['name']));

            return $this->ok(array_map(fn ($z) => $this->zoneOut($z), $zones));
        }
        if ($method === 'GET' && $path === '/accounts') {
            return $this->ok($this->accounts, ['page' => 1, 'total_pages' => 1]);
        }
        if ($method === 'POST' && $path === '/zones') {
            $name = (string) ($data['name'] ?? '');
            if ($this->zoneByName($name) !== null || in_array($name, $this->taken, true)) {
                return $this->error(400, 1061, "{$name} already exists");
            }
            $id = $this->addZone($name, [], 'pending');

            return $this->ok($this->zoneOut($this->zones[$id]));
        }
        if (preg_match('#^/zones/([^/]+)(/activation_check)?$#', $path, $m) && isset($this->zones[$m[1]])) {
            if ($method === 'PUT' && isset($m[2])) {
                $this->activationChecks++;

                return $this->ok(['id' => $m[1]]);
            }
            if ($method === 'GET' && !isset($m[2])) {
                return $this->ok($this->zoneOut($this->zones[$m[1]]));
            }
        }
        if ($method === 'POST' && preg_match('#^/zones/([^/]+)/dns_records/scan$#', $path, $m) && isset($this->zones[$m[1]])) {
            $found = $this->scannable[$this->zones[$m[1]]['name']] ?? [];
            foreach ($found as $r) {
                $this->store($m[1], $r);
            }

            return $this->ok(['recs_added' => count($found), 'total_records_parsed' => count($found)]);
        }
        if ($method === 'GET' && preg_match('#^/zones/([^/]+)/dnssec$#', $path, $m) && isset($this->zones[$m[1]])) {
            return $this->ok(['status' => $this->dnssec[$m[1]] ?? 'disabled']);
        }
        if (!preg_match('#^/zones/([^/]+)/dns_records(?:/([^/]+))?$#', $path, $m) || !isset($this->zones[$m[1]])) {
            return $this->error(404, 7003, 'Could not route');
        }
        [$zoneId, $id] = [$m[1], $m[2] ?? null];

        return match (true) {
            $method === 'GET' && $id === null => $this->ok(array_values($this->zones[$zoneId]['records']), ['page' => 1, 'total_pages' => 1]),
            $method === 'POST' && $id === 'batch' => $this->batch($zoneId, $data ?? []),
            $method === 'POST' && $id === null => $this->ok($this->store($zoneId, $data ?? [])),
            $method === 'PUT' && $id !== null => $this->ok($this->store($zoneId, ['id' => $id] + ($data ?? []))),
            $method === 'DELETE' && $id !== null => $this->delete($zoneId, $id),
            default => $this->error(405, 10000, 'Method not allowed'),
        };
    }

    private function zoneOut(array $z): array
    {
        return ['id' => $z['id'], 'name' => $z['name'], 'status' => $z['status'] ?? 'active', 'name_servers' => $z['name_servers'] ?? [],
            'original_name_servers' => $z['original_name_servers'] ?? [], 'original_registrar' => $z['original_registrar'] ?? null];
    }

    private function batch(string $zoneId, array $data): HttpResponse
    {
        foreach ($data['deletes'] ?? [] as $d) {
            unset($this->zones[$zoneId]['records'][$d['id']]);
        }
        foreach ($data['puts'] ?? [] as $p) {
            $this->store($zoneId, $p);
        }
        foreach ($data['posts'] ?? [] as $p) {
            $this->store($zoneId, $p);
        }

        return $this->ok([]);
    }

    private function store(string $zoneId, array $r): array
    {
        $r['id'] ??= 'rec' . (++$this->seq);
        $r['ttl'] ??= 1;
        if (($r['type'] ?? '') === 'SRV' && isset($r['data'])) {
            $r['content'] = sprintf('%d %d %s', $r['data']['weight'] ?? 0, $r['data']['port'] ?? 0, $r['data']['target'] ?? '');
            $r['priority'] = $r['data']['priority'] ?? 0;
        }
        if (($r['type'] ?? '') === 'TLSA' && isset($r['data'])) {
            $r['content'] = sprintf('%d %d %d %s', $r['data']['usage'], $r['data']['selector'], $r['data']['matching_type'], $r['data']['certificate']);
        }
        $this->zones[$zoneId]['records'][$r['id']] = $r;

        return $r;
    }

    private function delete(string $zoneId, string $id): HttpResponse
    {
        if (!isset($this->zones[$zoneId]['records'][$id])) {
            return $this->error(404, 81044, 'Record does not exist');
        }
        unset($this->zones[$zoneId]['records'][$id]);

        return $this->ok(['id' => $id]);
    }

    private function ok(array $result, ?array $info = null): HttpResponse
    {
        return new HttpResponse(200, json_encode(['success' => true, 'errors' => [], 'result' => $result] + ($info ? ['result_info' => $info] : [])));
    }

    private function error(int $status, int $code, string $message): HttpResponse
    {
        return new HttpResponse($status, json_encode(['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => null]));
    }
}
