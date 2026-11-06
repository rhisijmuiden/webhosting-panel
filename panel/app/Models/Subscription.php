<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription: one website on one hosting package (like a Plesk subscription).
 * The owner is the customer of the website. All limits of the package apply to
 * this website alone; the resource jail is the systemd slice whp-sites-s<id>.slice.
 */
class Subscription extends Model
{
    public const STATUSES = ['active', 'suspended'];

    /** Reason shown while a subscription is suspended because its expiry date passed. */
    public const EXPIRED = 'Expired';

    public const BY_ADMIN = 'Suspended by the administrator';

    protected $fillable = ['website_id', 'hosting_package_id', 'status', 'suspended_reason', 'expires_on', 'notes'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(HostingPackage::class, 'hosting_package_id');
    }

    /** The owner (customer of the website). */
    public function getCustomerAttribute(): ?Customer
    {
        return $this->website?->customer;
    }

    public function scopeOwnedBy(Builder $query, Customer|int $customer): Builder
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return $query->whereHas('website', fn ($q) => $q->where('customer_id', $id));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    /** systemd slice owner of the resource jail ("s12" -> whp-sites-s12.slice). */
    public function sliceOwner(): string
    {
        return 's'.$this->id;
    }

    /** "example.com (Webhosting M)", the name used in lists. */
    public function label(): string
    {
        $domain = $this->website?->domain ?? '#'.$this->id;

        return $this->package ? "{$domain} ({$this->package->name})" : $domain;
    }

    /** Data traffic this month as a percentage of the package limit (null: unlimited). */
    public function trafficPercent(): ?float
    {
        $limit = $this->package?->traffic_gb_month;

        return $limit && $this->website ? $this->website->trafficBytes() / ($limit * 1024 ** 3) * 100 : null;
    }

    /** Disk usage as a percentage of the package limit (null: unlimited). */
    public function diskPercent(): ?float
    {
        $limit = $this->website?->disk_quota_mb ?: $this->package?->disk_mb;

        return $limit ? min(100, ($this->website?->disk_used_mb ?? 0) / $limit * 100) : null;
    }
}
