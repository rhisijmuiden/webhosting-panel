<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

final readonly class SyncPlan
{
    /**
     * @param list<SyncChange> $changes
     * @param list<SyncConflict> $conflicts
     * @param list<DnsRecord> $unchanged
     */
    public function __construct(
        public string $zone,
        public array $changes,
        public array $conflicts,
        public array $unchanged,
    ) {
    }

    public function isInSync(): bool
    {
        return $this->changes === [] && $this->conflicts === [];
    }

    /** @return list<SyncChange> */
    public function ofAction(string $action): array
    {
        return array_values(array_filter($this->changes, static fn (SyncChange $c): bool => $c->action === $action));
    }

    /** @return array<string, mixed> JSON-friendly summary for the UI and the job log */
    public function toArray(): array
    {
        return [
            'zone' => $this->zone,
            'in_sync' => $this->isInSync(),
            'changes' => array_map(fn (SyncChange $c): array => $c->toArray($this->zone), $this->changes),
            'conflicts' => array_map(fn (SyncConflict $c): array => [
                'slot' => $c->slot,
                'message' => $c->messageKey(),
                'existing' => array_map(fn (DnsRecord $r): array => ['type' => $r->type, 'name' => $r->relativeName($this->zone), 'content' => $r->content], $c->existing),
            ], $this->conflicts),
            'unchanged' => count($this->unchanged),
        ];
    }
}
