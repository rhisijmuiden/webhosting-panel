<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A domain on its way to the RHIS Cloudflare account: the zone exists, the
 * customer changes the nameservers at their registrar, and the panel checks
 * until Cloudflare sees the change.
 *
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $activated_at
 */
class DnsOnboarding extends Model
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';

    /** Cloudflare removes zones that stay pending too long; we stop checking after this. */
    public const MAX_DAYS = 28;

    protected $attributes = ['status' => self::PENDING, 'imported_records' => 0, 'check_count' => 0];

    protected $fillable = [
        'mail_domain_id', 'zone_id', 'status', 'name_servers', 'original_name_servers', 'original_registrar',
        'current_name_servers', 'imported_records', 'check_count', 'last_checked_at', 'activated_at', 'notified_at', 'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'name_servers' => 'array', 'original_name_servers' => 'array', 'current_name_servers' => 'array',
            'imported_records' => 'integer', 'check_count' => 'integer',
            'last_checked_at' => 'datetime', 'activated_at' => 'datetime', 'notified_at' => 'datetime', 'seen_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Public DNS already shows exactly the assigned nameservers (Cloudflare may not have noticed yet). */
    public function nameserversMatch(): bool
    {
        $want = array_map('strtolower', (array) $this->name_servers);
        $have = array_map('strtolower', (array) $this->current_name_servers);
        sort($want);
        sort($have);

        return $want !== [] && $want === $have;
    }

    /**
     * Checks are frequent right after the start (customers change nameservers
     * while the wizard is open) and slow down later: every 5 minutes in the
     * first hour, every 15 minutes in the first two days, then hourly.
     */
    public function isDue(?Carbon $now = null): bool
    {
        $now ??= now();
        if (!$this->isPending()) {
            return false;
        }
        if ($this->last_checked_at === null) {
            return true;
        }
        $age = $this->created_at->diffInMinutes($now);
        $interval = match (true) {
            $age < 60 => 5,
            $age < 2 * 24 * 60 => 15,
            default => 60,
        };

        return $this->last_checked_at->diffInMinutes($now) >= $interval - 0.5;
    }
}
