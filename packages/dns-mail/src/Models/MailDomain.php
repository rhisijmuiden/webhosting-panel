<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rhis\DnsMail\Mail\MailProvider;

/**
 * A domain with its DNS and mail choices. One per website domain, created
 * automatically when a website is added; can also exist without a website.
 */
class MailDomain extends Model
{
    public const DMARC_POLICIES = ['none', 'quarantine', 'reject'];

    public const STS_MODES = ['testing', 'enforce', 'none'];

    /** Same defaults as the migration, so a new model behaves before it is reloaded. */
    protected $attributes = [
        'provider' => 'rhis', 'dns_managed' => true, 'proxied' => true, 'server_sends' => true, 'spf_all' => '~',
        'dmarc_policy' => 'quarantine', 'mta_sts_mode' => 'testing', 'm365_intune' => false, 'dns_status' => 'unknown',
    ];

    protected $fillable = [
        'customer_id', 'website_id', 'domain', 'provider', 'dns_managed', 'proxied', 'cloudflare_zone_id',
        'dkim_public_key', 'server_sends', 'spf_extra', 'spf_all', 'dmarc_policy', 'dmarc_rua', 'mta_sts_mode',
        'm365_tenant', 'm365_mx_host', 'm365_verification', 'm365_dkim_selector1', 'm365_dkim_selector2', 'm365_intune',
        'dns_status', 'dns_error', 'dns_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'dns_managed' => 'boolean', 'proxied' => 'boolean', 'server_sends' => 'boolean', 'm365_intune' => 'boolean',
            'dns_checked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class)->orderBy('local_part');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailAlias::class)->orderBy('local_part');
    }

    public function providerEnum(): MailProvider
    {
        return MailProvider::tryFrom($this->provider) ?? MailProvider::Rhis;
    }

    public function hasLocalMail(): bool
    {
        return $this->providerEnum() === MailProvider::Rhis;
    }

    /** Pill status for the DNS state. */
    public function dnsPill(): string
    {
        return match ($this->dns_status) {
            'in_sync' => 'ok',
            'changes' => 'warn',
            'conflict', 'error', 'no_zone' => 'bad',
            default => 'neutral',
        };
    }
}
