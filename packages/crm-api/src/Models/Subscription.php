<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Website;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasUlids;

    protected $table = 'crm_subscriptions';

    protected $fillable = [
        'external_id', 'customer_id', 'hosting_package_id', 'website_id', 'primary_domain', 'status',
        'php_version', 'limit_overrides', 'options', 'suspension', 'terminated_at', 'keep_backup_until',
    ];

    protected function casts(): array
    {
        return [
            'limit_overrides' => 'array', 'options' => 'array', 'suspension' => 'array',
            'terminated_at' => 'datetime', 'keep_backup_until' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(HostingPackage::class, 'hosting_package_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
