<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/**
 * Works out which changes bring a Cloudflare zone to the desired records.
 *
 * Records are grouped in "slots": a name plus a kind of data, for example
 * "example.com|mx" or "_dmarc.example.com|dmarc". The panel owns a slot when
 * every record in it carries the managed marker in its Cloudflare comment.
 *
 * - Desired slot, only managed (or no) records: create, update and delete freely.
 * - Desired slot that holds records the panel did not create: report a conflict
 *   and touch nothing, unless $adopt is true (the admin chose "take over").
 * - Managed records in a slot that is no longer desired: delete them.
 * - Unmanaged records in other slots: never touched.
 */
final class SyncPlanner
{
    /**
     * @param list<DnsRecord> $desired
     * @param list<DnsRecord> $existing what Cloudflare has now
     * @param string|null $note text added after the managed marker, e.g. "mail:rhis"
     */
    public function plan(string $zone, array $desired, array $existing, bool $adopt = false, ?string $note = null): SyncPlan
    {
        $zone = DnsRecord::normaliseName($zone);
        $desiredBySlot = $this->group($desired);
        $existingBySlot = $this->group($existing);

        $changes = [];
        $conflicts = [];
        $unchanged = [];

        foreach ($desiredBySlot as $slot => $want) {
            $have = $existingBySlot[$slot] ?? [];
            $foreign = array_values(array_filter($have, static fn (DnsRecord $r): bool => !$r->isManaged()));
            if ($foreign !== [] && !$adopt) {
                if ($this->sameValues($want, $have)) {
                    // The data is already right, just not ours: nothing to do.
                    array_push($unchanged, ...$have);
                } else {
                    $conflicts[] = new SyncConflict($slot, $have, $want);
                }
                continue;
            }
            array_push($changes, ...$this->diffSlot($want, $have, $note, $unchanged));
        }

        foreach ($existingBySlot as $slot => $have) {
            if (isset($desiredBySlot[$slot])) {
                continue;
            }
            foreach ($have as $record) {
                if ($record->isManaged()) {
                    $changes[] = new SyncChange(SyncChange::DELETE, $record);
                }
            }
        }

        return new SyncPlan($zone, $changes, $conflicts, $unchanged);
    }

    /**
     * @param list<DnsRecord> $want
     * @param list<DnsRecord> $have
     * @param list<DnsRecord> $unchanged
     * @return list<SyncChange>
     */
    private function diffSlot(array $want, array $have, ?string $note, array &$unchanged): array
    {
        $changes = [];
        $leftover = [];
        $byPrint = [];
        foreach ($have as $r) {
            $byPrint[$r->fingerprint()][] = $r;
        }

        $missing = [];
        foreach ($want as $w) {
            $w = $w->withManagedComment($note);
            $print = $w->fingerprint();
            $match = isset($byPrint[$print]) ? array_shift($byPrint[$print]) : null;
            if ($match === null) {
                $missing[] = $w;
                continue;
            }
            if ($match->sameSettings($w) && $match->comment === $w->comment) {
                $unchanged[] = $match;
            } else {
                $changes[] = new SyncChange(SyncChange::UPDATE, $w->with(id: $match->id), $match);
            }
        }
        foreach ($byPrint as $rest) {
            array_push($leftover, ...$rest);
        }

        // Reuse leftover records of the same type for missing ones (update instead of delete+create).
        foreach ($missing as $w) {
            $reuseKey = null;
            foreach ($leftover as $i => $old) {
                if ($old->type === $w->type) {
                    $reuseKey = $i;
                    break;
                }
            }
            if ($reuseKey === null) {
                $changes[] = new SyncChange(SyncChange::CREATE, $w);
                continue;
            }
            $old = $leftover[$reuseKey];
            unset($leftover[$reuseKey]);
            $changes[] = new SyncChange(SyncChange::UPDATE, $w->with(id: $old->id), $old);
        }
        foreach ($leftover as $old) {
            $changes[] = new SyncChange(SyncChange::DELETE, $old);
        }

        return $changes;
    }

    /**
     * @param list<DnsRecord> $a
     * @param list<DnsRecord> $b
     */
    private function sameValues(array $a, array $b): bool
    {
        $pa = array_map(static fn (DnsRecord $r): string => $r->fingerprint(), $a);
        $pb = array_map(static fn (DnsRecord $r): string => $r->fingerprint(), $b);
        sort($pa);
        sort($pb);

        return $pa === $pb;
    }

    /**
     * @param list<DnsRecord> $records
     * @return array<string, list<DnsRecord>>
     */
    private function group(array $records): array
    {
        $slots = [];
        foreach ($records as $r) {
            $slots[self::slotOf($r)][] = $r;
        }

        return $slots;
    }

    public static function slotOf(DnsRecord $r): string
    {
        $kind = match ($r->type) {
            // CNAME cannot live next to A/AAAA, so they share one slot.
            'A', 'AAAA', 'CNAME' => 'address',
            'MX' => 'mx',
            'SRV' => 'srv',
            'CAA' => 'caa',
            'TLSA' => 'tlsa',
            'TXT' => match (true) {
                str_starts_with(strtolower($r->content), 'v=spf1') => 'spf',
                str_starts_with(strtolower($r->content), 'v=dmarc1') => 'dmarc',
                str_starts_with(strtolower($r->content), 'v=dkim1') => 'dkim',
                str_starts_with(strtolower($r->content), 'ms=') => 'ms-verify',
                str_starts_with(strtolower($r->content), 'v=bimi1') => 'bimi',
                default => 'txt:' . substr(sha1($r->content), 0, 12),
            },
        };

        return $r->name . '|' . $kind;
    }
}
