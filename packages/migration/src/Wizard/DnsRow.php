<?php

declare(strict_types=1);

namespace Rhis\Migration\Wizard;

use Rhis\DnsMail\Dns\DnsRecord;

/**
 * One line of the DNS comparison in the migration wizard.
 *
 * action: create, update or delete (the admin can tick these), same (already
 * right) or untouched (a record the switch never changes).
 * origin: panel (a record this server needs) or plesk (the domain's own record
 * from the old server).
 */
final readonly class DnsRow
{
    public const CHANGES = ['create', 'update', 'delete'];

    public function __construct(
        public string $action,
        public string $group,
        public ?DnsRecord $new,
        public ?DnsRecord $old,
        public string $origin = 'panel',
        public bool $selected = true,
        public ?string $hint = null,
    ) {}

    public function isChange(): bool
    {
        return in_array($this->action, self::CHANGES, true);
    }

    /** Stable between showing the list and applying it; changes when Cloudflare changed in between. */
    public function key(): string
    {
        return substr(sha1(implode('#', [
            $this->action, $this->origin,
            $this->new?->fingerprint() ?? '', $this->new ? (int) $this->new->proxied.'/'.$this->new->ttl : '',
            $this->old?->id ?? '', $this->old?->fingerprint() ?? '',
        ])), 0, 16);
    }

    public function type(): string
    {
        return ($this->new ?? $this->old)->type;
    }

    public function name(string $zone): string
    {
        return ($this->new ?? $this->old)->relativeName($zone);
    }

    /** @return array<string, mixed> for the snapshot and the result summary */
    public function toArray(string $zone): array
    {
        return [
            'key' => $this->key(), 'action' => $this->action, 'group' => $this->group, 'origin' => $this->origin,
            'type' => $this->type(), 'name' => $this->name($zone),
            'old' => $this->old?->displayValue(), 'new' => $this->new?->displayValue(),
            'new_fingerprint' => $this->new?->fingerprint(),
        ];
    }
}
