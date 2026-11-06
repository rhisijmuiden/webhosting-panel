<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use App\Models\Customer;
use App\Models\Website;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A problem from the moment it started until it was solved. */
class Incident extends Model
{
    protected $table = 'monitor_incidents';

    protected $fillable = ['key', 'kind', 'website_id', 'customer_id', 'title', 'severity', 'detail', 'started_at', 'resolved_at', 'notified_at', 'reminders'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'resolved_at' => 'datetime', 'notified_at' => 'datetime', 'reminders' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    /** Incidents that were (partly) open between $from and $to. */
    public function scopeBetween(Builder $q, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $q->where('started_at', '<', $to)->where(fn ($w) => $w->whereNull('resolved_at')->orWhere('resolved_at', '>', $from));
    }

    public function durationSeconds(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        $start = $from && $this->started_at->lessThan($from) ? $from : $this->started_at;
        $end = $this->resolved_at ?? now();
        if ($to && $end->greaterThan($to)) {
            $end = $to;
        }

        return max(0, (int) $start->diffInSeconds($end, false));
    }

    public function duration(): string
    {
        $s = $this->durationSeconds();

        return $s < 60 ? __(':n sec', ['n' => $s]) : \Carbon\CarbonInterval::seconds($s)->cascade()->forHumans(['parts' => 2, 'short' => true]);
    }
}
