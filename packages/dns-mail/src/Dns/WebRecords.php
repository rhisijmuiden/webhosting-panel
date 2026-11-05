<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/** Records that point a hosted site at this server. */
final class WebRecords
{
    /**
     * @param list<string> $aliases extra names besides the apex, "www" by default
     * @return list<DnsRecord>
     */
    public static function forSite(string $domain, ?string $ipv4, ?string $ipv6, bool $proxied = true, array $aliases = ['www']): array
    {
        $domain = DnsRecord::normaliseName($domain);
        $records = [];
        foreach (['', ...$aliases] as $label) {
            $name = $label === '' || $label === '@' ? $domain : $label . '.' . $domain;
            if ($ipv4 !== null && $ipv4 !== '') {
                $records[] = new DnsRecord('A', $name, $ipv4, proxied: $proxied);
            }
            if ($ipv6 !== null && $ipv6 !== '') {
                $records[] = new DnsRecord('AAAA', $name, $ipv6, proxied: $proxied);
            }
        }

        return $records;
    }
}
