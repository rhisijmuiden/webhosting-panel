<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/**
 * Looks up what the public DNS says right now. Used to see where a domain's
 * nameservers point and to check records of domains whose DNS is hosted
 * elsewhere.
 */
interface Resolver
{
    /**
     * Records of one type at one name, as DnsRecord objects (content in the
     * same form the panel uses: TXT unquoted, MX/CNAME without trailing dot).
     * An empty list means "nothing there"; lookup failures also return [].
     *
     * @return list<DnsRecord>
     */
    public function lookup(string $name, string $type): array;

    /**
     * Nameservers the domain is delegated to (NS at the apex), lower case,
     * sorted. Empty when the lookup fails.
     *
     * @return list<string>
     */
    public function nameservers(string $domain): array;
}
