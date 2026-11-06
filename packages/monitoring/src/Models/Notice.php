<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A message on the status page: planned maintenance or a known problem. */
class Notice extends Model
{
    public const LEVELS = ['info', 'maintenance', 'degraded', 'outage'];

    protected $table = 'monitor_notices';

    protected $fillable = ['title', 'body', 'level', 'components', 'starts_at', 'ends_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['components' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** Shown now: not solved and not ended (planned ones are shown ahead). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereNull('resolved_at')->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isUpcoming(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isFuture();
    }
}
