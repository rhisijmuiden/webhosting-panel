<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'company', 'email', 'phone', 'crm_id', 'status', 'locale', 'notes',
    ];

    /** The subscriptions (website + package) this customer owns. */
    public function subscriptions(): HasManyThrough
    {
        return $this->hasManyThrough(Subscription::class, Website::class);
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

    public function diskUsedMb(): int
    {
        return (int) $this->websites->sum('disk_used_mb');
    }
}
