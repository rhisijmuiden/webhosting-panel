<?php

declare(strict_types=1);

namespace Rhis\Migration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\DnsMail\Dns\DnsRecord;

/** Cloudflare records of a domain right before the migration wizard switched its DNS. */
class MigrationDnsSnapshot extends Model
{
    protected $fillable = ['migration_item_id', 'domain', 'zone_id', 'records', 'changes', 'created_by', 'rolled_back_at'];

    protected function casts(): array
    {
        return ['records' => 'array', 'changes' => 'array', 'rolled_back_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MigrationItem::class, 'migration_item_id');
    }

    /** @return array<string, mixed> */
    public static function pack(DnsRecord $r): array
    {
        return ['type' => $r->type, 'name' => $r->name, 'content' => $r->content, 'ttl' => $r->ttl, 'proxied' => $r->proxied,
            'priority' => $r->priority, 'srv' => $r->srv, 'id' => $r->id, 'comment' => $r->comment];
    }

    /** @param array<string, mixed> $a */
    public static function unpack(array $a): DnsRecord
    {
        return new DnsRecord((string) $a['type'], (string) $a['name'], (string) $a['content'], (int) ($a['ttl'] ?? 1),
            $a['proxied'] ?? null, $a['priority'] ?? null, $a['srv'] ?? null, $a['id'] ?? null, $a['comment'] ?? null);
    }

    /** @return list<DnsRecord> */
    public function oldRecords(): array
    {
        return array_map(self::unpack(...), $this->records ?? []);
    }
}
