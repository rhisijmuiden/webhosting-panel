<?php

declare(strict_types=1);

namespace Rhis\Certificates\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The certificate a website serves, as last read from the server. */
class SiteCertificate extends Model
{
    public const SOURCES = ['letsencrypt', 'wildcard', 'custom', 'imported', 'none'];

    protected $fillable = [
        'website_id', 'source', 'wildcard_of', 'common_name', 'names', 'issuer', 'not_before', 'not_after',
        'fingerprint', 'self_signed', 'chain_length', 'status', 'requested', 'last_error', 'warned_days', 'checked_at',
        'csr', 'csr_key', 'csr_created_at',
    ];

    protected $hidden = ['csr_key'];

    protected function casts(): array
    {
        return [
            'names' => 'array', 'not_before' => 'datetime', 'not_after' => 'datetime', 'checked_at' => 'datetime',
            'csr_created_at' => 'datetime', 'self_signed' => 'boolean', 'csr_key' => 'encrypted',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function daysLeft(): ?int
    {
        return $this->not_after ? (int) floor((($this->not_after->getTimestamp() - time()) / 86400)) : null;
    }

    public function isExpired(): bool
    {
        return $this->not_after !== null && $this->not_after->isPast();
    }

    /** Renewed by the server itself (Let's Encrypt, normal or wildcard). */
    public function renewsAutomatically(): bool
    {
        return in_array($this->source, ['letsencrypt', 'wildcard'], true);
    }

    /** ok, warn or bad for the expiry. */
    public function health(): string
    {
        $days = $this->daysLeft();
        if ($this->source === 'none' || $days === null) {
            return 'warn';
        }
        if ($days < 0) {
            return 'bad';
        }
        // Let's Encrypt renews well before expiry (also its shorter 45-day
        // certificates), so only a few days left means renewals are failing.
        [$warn, $bad] = $this->renewsAutomatically() ? [10, 5] : [30, 7];
        if ($days <= $bad) {
            return 'bad';
        }

        return $days <= $warn ? 'warn' : 'ok';
    }

    public function isBusy(): bool
    {
        return $this->status === 'busy';
    }
}
