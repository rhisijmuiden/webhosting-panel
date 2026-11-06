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

    public function addZone(string $name, array $records = []): string
    {
        $id = 'zone' . (++$this->seq);
        $this->zones[$id] = ['id' => $id, 'name' => $name, 'records' => []];
        foreach ($records as $r) {
            $this->store($id, $r);
        }

        return $id;
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

        if ($method === 'GET' && $path === '/user/tokens/verify') {
            return $this->ok(['id' => 'tok', 'status' => 'active']);
        }
        if ($method === 'GET' && $path === '/zones') {
            $zones = array_values(array_filter($this->zones, fn ($z) => !isset($query['name']) || $z['name'] === $query['name']));

            return $this->ok(array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['name'], 'status' => 'active'], $zones));
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
