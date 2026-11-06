<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

use Throwable;

/**
 * Resolver on top of PHP's dns_get_record(), i.e. the server's own resolver
 * (Unbound on a panel server). Answers can be cached up to the record's TTL.
 */
final class SystemResolver implements Resolver
{
    private const TYPES = [
        'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX,
        'TXT' => DNS_TXT, 'SRV' => DNS_SRV,
    ];

    public function lookup(string $name, string $type): array
    {
        $type = strtoupper($type);
        $flag = self::TYPES[$type] ?? null;
        if ($flag === null) {
            return [];
        }
        try {
            $answers = @dns_get_record(DnsRecord::normaliseName($name), $flag);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            // A CNAME at the name is returned for other types too; keep only the asked type.
            if (strtoupper((string) ($a['type'] ?? '')) !== $type) {
                continue;
            }
            $record = self::toRecord($type, $a);
            if ($record !== null) {
                $out[] = $record;
            }
        }

        return $out;
    }

    public function nameservers(string $domain): array
    {
        try {
            $answers = @dns_get_record(DnsRecord::normaliseName($domain), DNS_NS);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            if (strtoupper((string) ($a['type'] ?? '')) === 'NS' && ($a['target'] ?? '') !== '') {
                $out[] = DnsRecord::normaliseName((string) $a['target']);
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $a */
    private static function toRecord(string $type, array $a): ?DnsRecord
    {
        $host = (string) ($a['host'] ?? '');
        $ttl = (int) ($a['ttl'] ?? 1);

        return match ($type) {
            'A' => new DnsRecord('A', $host, (string) ($a['ip'] ?? ''), $ttl),
            'AAAA' => new DnsRecord('AAAA', $host, (string) ($a['ipv6'] ?? ''), $ttl),
            'CNAME' => new DnsRecord('CNAME', $host, (string) ($a['target'] ?? ''), $ttl),
            'MX' => new DnsRecord('MX', $host, (string) ($a['target'] ?? ''), $ttl, priority: (int) ($a['pri'] ?? 0)),
            'TXT' => new DnsRecord('TXT', $host, implode('', (array) ($a['entries'] ?? [$a['txt'] ?? ''])), $ttl),
            'SRV' => new DnsRecord('SRV', $host, '', $ttl, srv: [
                'priority' => (int) ($a['pri'] ?? 0), 'weight' => (int) ($a['weight'] ?? 0),
                'port' => (int) ($a['port'] ?? 0), 'target' => rtrim(strtolower((string) ($a['target'] ?? '')), '.') ?: '.',
            ]),
            default => null,
        };
    }
}
