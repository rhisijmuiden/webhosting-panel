<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/**
 * A slot where Cloudflare holds records the panel did not create. The panel
 * leaves them alone unless the admin chooses to take the slot over.
 */
final readonly class SyncConflict
{
    /**
     * @param list<DnsRecord> $existing
     * @param list<DnsRecord> $desired
     */
    public function __construct(
        public string $slot,
        public array $existing,
        public array $desired,
    ) {
    }

    /** Translation key in lang/{en,nl}/dns-mail.php */
    public function messageKey(): string
    {
        $kind = explode('|', $this->slot)[1] ?? 'txt';

        return 'dns-mail::dns-mail.conflict.' . explode(':', $kind)[0];
    }
}
