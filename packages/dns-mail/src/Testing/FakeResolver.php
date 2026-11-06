<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Testing;

use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\Resolver;

/** In-memory public DNS for tests: set records and nameservers by hand. */
final class FakeResolver implements Resolver
{
    /** @var list<DnsRecord> */
    public array $records = [];

    /** @var array<string, list<string>> */
    public array $ns = [];

    public function add(DnsRecord ...$records): self
    {
        array_push($this->records, ...$records);

        return $this;
    }

    public function delegate(string $domain, string ...$nameservers): self
    {
        $this->ns[DnsRecord::normaliseName($domain)] = array_map(DnsRecord::normaliseName(...), $nameservers);

        return $this;
    }

    public function lookup(string $name, string $type): array
    {
        $name = DnsRecord::normaliseName($name);

        return array_values(array_filter($this->records, static fn (DnsRecord $r): bool => $r->name === $name && $r->type === strtoupper($type)));
    }

    public function nameservers(string $domain): array
    {
        $ns = $this->ns[DnsRecord::normaliseName($domain)] ?? [];
        sort($ns);

        return $ns;
    }
}
