<?php

namespace App\Models;

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
