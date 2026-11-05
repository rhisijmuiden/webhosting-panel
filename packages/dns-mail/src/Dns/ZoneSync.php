<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

use Rhis\DnsMail\Cloudflare\CloudflareClient;
use RuntimeException;

/**
 * Plans and applies the desired records for one domain on Cloudflare.
 *
 * Typical use from the panel: plan() to show a preview, apply() after the
 * admin (or the nightly drift check) confirms.
 */
final class ZoneSync
{
    public function __construct(
        private readonly CloudflareClient $cloudflare,
        private readonly SyncPlanner $planner = new SyncPlanner(),
    ) {
    }

    /** @param list<DnsRecord> $desired */
    public function plan(string $domain, array $desired, bool $adopt = false, ?string $note = null): SyncPlan
    {
        $zone = $this->zoneOrFail($domain);

        return $this->planner->plan($zone['name'], $desired, $this->cloudflare->listRecords($zone['id']), $adopt, $note);
    }

    /** @param list<DnsRecord> $desired */
    public function apply(string $domain, array $desired, bool $adopt = false, ?string $note = null): SyncPlan
    {
        $zone = $this->zoneOrFail($domain);
        $plan = $this->planner->plan($zone['name'], $desired, $this->cloudflare->listRecords($zone['id']), $adopt, $note);
        $this->applyPlan($zone['id'], $plan);

        return $plan;
    }

    public function applyPlan(string $zoneId, SyncPlan $plan): void
    {
        $this->cloudflare->batch(
            $zoneId,
            array_map(static fn (SyncChange $c): DnsRecord => $c->record, $plan->ofAction(SyncChange::CREATE)),
            array_map(static fn (SyncChange $c): DnsRecord => $c->record, $plan->ofAction(SyncChange::UPDATE)),
            array_map(static fn (SyncChange $c): string => (string) $c->record->id, $plan->ofAction(SyncChange::DELETE)),
        );
    }

    /** @return array{id: string, name: string, status: string} */
    private function zoneOrFail(string $domain): array
    {
        return $this->cloudflare->findZone($domain)
            ?? throw new RuntimeException("Domain {$domain} is not in the connected Cloudflare account");
    }
}
