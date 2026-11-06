<?php

declare(strict_types=1);

namespace Rhis\Migration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A server to migrate from (today Plesk, reached over SSH as root). */
class MigrationSource extends Model
{
    public const TYPES = ['plesk' => 'Plesk'];

    protected $fillable = [
        'name', 'type', 'host', 'port', 'user', 'status', 'version', 'hostname', 'host_key', 'last_error',
        'inventory_job', 'inventory', 'inventoried_at',
    ];

    protected function casts(): array
    {
        return ['inventory' => 'array', 'inventoried_at' => 'datetime', 'port' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MigrationItem::class)->orderBy('domain');
    }

    /** host, port, user and type for every agent call. */
    public function connection(): array
    {
        return ['type' => $this->type, 'host' => $this->host, 'port' => $this->port, 'user' => $this->user];
    }

    /** @return array<string, mixed>|null */
    public function inventoryDomain(string $domain): ?array
    {
        foreach ($this->inventory['domains'] ?? [] as $d) {
            if (($d['name'] ?? null) === $domain) {
                return $d;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function owner(?string $id): ?array
    {
        foreach ($this->inventory['owners'] ?? [] as $o) {
            if ((string) ($o['id'] ?? '') === (string) $id) {
                return $o;
            }
        }

        return null;
    }
}
