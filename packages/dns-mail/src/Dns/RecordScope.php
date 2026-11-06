<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/**
 * Which records of a zone belong to one domain's sync.
 *
 * A zone can hold more than the domain being synced: "shop.example.com" may
 * be its own domain in the panel, and the server's own records (DANE for the
 * mail host) can live in a customer zone. Without this filter, syncing one
 * would delete the other's managed records as "no longer wanted".
 */
final class RecordScope
{
    public const string SERVER_NOTE = 'server';

    /**
     * @param list<DnsRecord> $records everything in the zone
     * @param list<string> $separateDomains other panel domains in the same zone
     * @return list<DnsRecord>
     */
    public static function forDomain(string $domain, array $records, array $separateDomains = []): array
    {
        $domain = DnsRecord::normaliseName($domain);
        $nested = array_values(array_filter(
            array_map(DnsRecord::normaliseName(...), $separateDomains),
            static fn (string $d): bool => str_ends_with($d, '.' . $domain),
        ));

        return array_values(array_filter($records, static function (DnsRecord $r) use ($domain, $nested): bool {
            if (!self::within($r->name, $domain) || self::isServerRecord($r)) {
                return false;
            }
            foreach ($nested as $n) {
                if (self::within($r->name, $n)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Server-level records at exactly these names (DANE sync).
     *
     * @param list<DnsRecord> $records
     * @param list<string> $names
     * @return list<DnsRecord>
     */
    public static function atNames(array $records, array $names, string $type): array
    {
        $names = array_map(DnsRecord::normaliseName(...), $names);

        return array_values(array_filter($records, static fn (DnsRecord $r): bool => $r->type === $type && in_array($r->name, $names, true)));
    }

    public static function isServerRecord(DnsRecord $r): bool
    {
        return $r->comment !== null && str_starts_with($r->comment, DnsRecord::MANAGED_MARKER . ' ' . self::SERVER_NOTE);
    }

    private static function within(string $name, string $domain): bool
    {
        return $name === $domain || str_ends_with($name, '.' . $domain);
    }
}
