<?php

declare(strict_types=1);

namespace Rhis\Migration\Wizard;

use App\Models\ActivityLog;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DnsSync;
use Rhis\Migration\Models\MigrationDnsSnapshot;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Services\DnsCutover;
use RuntimeException;

/**
 * The definitive DNS switch of the wizard: only the lines the admin ticked go
 * to Cloudflare, in one atomic batch, after a snapshot of the old records.
 * The snapshot can put the old records back.
 */
final class DnsSwitch
{
    public function __construct(
        private readonly DnsSync $dns,
        private readonly DnsDiff $diff,
        private readonly DnsCutover $cutover,
    ) {}

    /**
     * @param  list<string>  $keys  the ticked lines (DnsRow::key())
     * @return array{mode: string, imported: int, changed: int, kept: int, skipped: list<string>, snapshot: int}
     */
    public function apply(MigrationItem $item, array $keys): array
    {
        $domain = MailDomain::where('domain', $item->domain)->firstOrFail();
        $zoneId = $this->dns->zoneId($domain) ?? throw new RuntimeException(__('This domain is not in the connected Cloudflare account.'));
        $existing = $this->diff->current($domain, $zoneId);
        ['rows' => $rows, 'skipped' => $skipped] = $this->diff->rows($item, $domain, $existing);

        $byKey = [];
        foreach ($rows as $r) {
            if ($r->isChange()) {
                $byKey[$r->key()] = $r;
            }
        }
        $missing = array_diff($keys, array_keys($byKey));
        if ($missing !== []) {
            throw new RuntimeException(__('The DNS records in Cloudflare changed since you looked at them. Check the list again and confirm.'));
        }
        $chosen = array_values(array_intersect_key($byKey, array_flip($keys)));

        $snapshot = MigrationDnsSnapshot::create([
            'migration_item_id' => $item->id, 'domain' => $domain->domain, 'zone_id' => $zoneId,
            'records' => array_map(MigrationDnsSnapshot::pack(...), $existing),
            'changes' => array_map(fn (DnsRow $r) => $r->toArray($domain->domain), $chosen),
            'created_by' => auth()->user()?->email,
        ]);

        $creates = $updates = $deletes = [];
        foreach ($chosen as $r) {
            match ($r->action) {
                'create' => $creates[] = $r->new,
                'update' => $updates[] = $r->new,
                'delete' => $deletes[] = (string) $r->old->id,
            };
        }
        try {
            $this->dns->client()->batch($zoneId, $creates, $updates, $deletes);
        } catch (\Throwable $e) {
            $snapshot->delete(); // Cloudflare applies all or nothing: nothing to undo.
            throw $e;
        }

        $domain->update(['dns_managed' => true, 'dns_status' => count($chosen) === count($byKey) ? 'in_sync' : 'conflict', 'dns_error' => null, 'dns_checked_at' => now()]);
        $result = [
            'mode' => 'cloudflare',
            'imported' => count(array_filter($chosen, fn (DnsRow $r) => $r->origin === 'plesk')),
            'changed' => count($chosen),
            'kept' => count($byKey) - count($chosen),
            'conflicts' => 0,
            'skipped' => $skipped,
            'snapshot' => $snapshot->id,
        ];
        $item->log('DNS switched with the wizard: :changed changes, :kept kept as they were.', ['changed' => $result['changed'], 'kept' => $result['kept']], 'ok');
        $item->setWizard('switched_at', now()->toIso8601String());
        $this->cutover->finish($item, $result);
        ActivityLog::record('migration.dns_wizard_applied', $domain->domain, ['changed' => $result['changed'], 'kept' => $result['kept'], 'snapshot' => $snapshot->id], $item->customer_id);

        return $result;
    }

    /**
     * Put the records of the snapshot back: changed ones get their old value,
     * deleted ones come back and the ones the switch created are removed.
     * Records added afterwards by hand stay.
     *
     * @return int number of changes made
     */
    public function rollback(MigrationDnsSnapshot $snapshot): int
    {
        if ($snapshot->rolled_back_at !== null) {
            throw new RuntimeException(__('This snapshot was already put back.'));
        }
        $item = $snapshot->item;
        $domain = MailDomain::where('domain', $snapshot->domain)->firstOrFail();
        $current = $this->diff->current($domain, $snapshot->zone_id);
        $old = $snapshot->oldRecords();

        $currentById = [];
        foreach ($current as $r) {
            $currentById[(string) $r->id] = $r;
        }
        $oldIds = [];
        $creates = $updates = $deletes = [];
        foreach ($old as $r) {
            $oldIds[(string) $r->id] = true;
            $now = $currentById[(string) $r->id] ?? null;
            if ($now === null) {
                $creates[] = new DnsRecord($r->type, $r->name, $r->content, $r->ttl, $r->proxied, $r->priority, $r->srv, null, $r->comment);
            } elseif ($now->fingerprint() !== $r->fingerprint() || ! $now->sameSettings($r) || $now->comment !== $r->comment) {
                $updates[] = $r;
            }
        }
        $created = array_filter(array_column(array_filter($snapshot->changes ?? [], fn ($c) => $c['action'] === 'create'), 'new_fingerprint'));
        foreach ($current as $r) {
            if (! isset($oldIds[(string) $r->id]) && in_array($r->fingerprint(), $created, true)) {
                $deletes[] = (string) $r->id;
            }
        }

        $this->dns->client()->batch($snapshot->zone_id, $creates, $updates, $deletes);
        // Stop the panel's own sync from writing the new records again.
        $domain->update(['dns_managed' => false, 'dns_status' => 'changes', 'dns_checked_at' => now()]);
        $snapshot->update(['rolled_back_at' => now()]);

        $n = count($creates) + count($updates) + count($deletes);
        if ($item !== null) {
            $item->log('DNS put back as it was before the switch (:count changes).', ['count' => $n], 'warn');
            $item->setWizard('switched_at', null);
            $item->setWizard('confirmed.final', null);
            $item->status = in_array($item->status, ['live', 'done'], true) ? 'copied' : $item->status;
            $item->cutover_at = null;
            $item->save();
        }
        ActivityLog::record('migration.dns_rolled_back', $snapshot->domain, ['snapshot' => $snapshot->id, 'changes' => $n], $item?->customer_id);

        return $n;
    }
}
