<?php

namespace App\Models;

use App\Jobs\ApplyCustomerLimits;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'company', 'email', 'phone', 'crm_id', 'hosting_package_id', 'status', 'locale', 'notes',
    ];

    protected static function booted(): void
    {
        // A new package means new resource limits on the server.
        static::updated(function (Customer $customer) {
            if ($customer->wasChanged('hosting_package_id') && $customer->websites()->exists()) {
                ApplyCustomerLimits::dispatch($customer);
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(HostingPackage::class, 'hosting_package_id');
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function displayName(): string
    {
        return $this->company ?: $this->name;
    }

    /** Data traffic of all websites this month, in bytes. */
    public function trafficBytes(?string $month = null): int
    {
        return (int) WebsiteTraffic::whereIn('website_id', $this->websites()->select('id'))
            ->where('month', $month ?? now()->utc()->format('Y-m'))->sum('bytes');
    }

    /** Traffic used as a percentage of the package limit (null: unlimited). */
    public function trafficPercent(): ?float
    {
        $limit = $this->package?->traffic_gb_month;

        return $limit ? $this->trafficBytes() / ($limit * 1024 ** 3) * 100 : null;
    }

    public function diskUsedMb(): int
    {
        return (int) $this->websites->sum('disk_used_mb');
    }

    public function canAddWebsite(): bool
    {
        $max = $this->package?->max_websites;

        return $max === null || $this->websites()->count() < $max;
    }
}
