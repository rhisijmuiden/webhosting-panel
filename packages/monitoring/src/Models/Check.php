<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The current state of one watched thing (website, certificate, disk, service, ...). */
class Check extends Model
{
    protected $table = 'monitor_checks';

    protected $fillable = ['key', 'kind', 'website_id', 'label', 'status', 'fails', 'value', 'detail', 'since', 'checked_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'since' => 'datetime', 'checked_at' => 'datetime', 'fails' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isProblem(): bool
    {
        return in_array($this->status, ['warning', 'critical'], true);
    }
}
