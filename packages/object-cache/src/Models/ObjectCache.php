<?php

declare(strict_types=1);

namespace Rhis\ObjectCache\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The object cache of one website: what the panel wants and what the server last reported. */
class ObjectCache extends Model
{
    protected $fillable = ['website_id', 'enabled', 'wordpress', 'memory_mb', 'state', 'installs', 'last_error', 'flushed_at'];

    protected $attributes = ['enabled' => false, 'wordpress' => true, 'state' => 'off'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'wordpress' => 'boolean', 'installs' => 'array', 'flushed_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isBusy(): bool
    {
        return $this->state === 'applying';
    }

    /** WordPress installs on the site that use the cache. */
    public function configuredInstalls(): array
    {
        return array_values(array_filter($this->installs ?? [], fn ($i) => ($i['configured'] ?? false) === true));
    }

    public function failedInstalls(): array
    {
        return array_values(array_filter($this->installs ?? [], fn ($i) => ! empty($i['error'])));
    }

    public function hasWooCommerce(): bool
    {
        return collect($this->installs ?? [])->contains(fn ($i) => ($i['woocommerce'] ?? false) === true);
    }
}
