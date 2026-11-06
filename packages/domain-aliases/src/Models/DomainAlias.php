<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\CrmApi\Models\Order;

/** An extra domain on a website: serves the same files or redirects to the website. */
class DomainAlias extends Model
{
    public const MODES = ['serve', 'redirect'];

    protected $attributes = [
        'mode' => 'serve', 'www' => true, 'mail' => true, 'ssl_status' => 'pending', 'status' => 'provisioning', 'created_by' => 'panel',
    ];

    protected $fillable = [
        'website_id', 'customer_id', 'domain', 'mode', 'www', 'mail', 'ssl_status', 'status', 'last_error',
        'upgrade_order_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['www' => 'boolean', 'mail' => 'boolean'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->www ? [$this->domain, 'www.'.$this->domain] : [$this->domain];
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['provisioning', 'removing'], true);
    }

    public function upgradeOrder(): ?Model
    {
        if (! $this->upgrade_order_id || ! class_exists(Order::class)) {
            return null;
        }

        return Order::find($this->upgrade_order_id);
    }
}
