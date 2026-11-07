<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rhis\WordPress\Bulk\BulkAction;
use Rhis\WordPress\Bulk\BulkActions;

/** One bulk action started by an admin on a set of WordPress installs. */
class BulkRun extends Model
{
    protected $table = 'wp_bulk_runs';

    protected $fillable = ['action', 'options', 'summary', 'status', 'concurrency', 'stop_after_failures', 'total',
        'user_id', 'user_name', 'stop_reason', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'concurrency' => 'integer', 'stop_after_failures' => 'integer', 'total' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkRunItem::class, 'run_id')->orderBy('position');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function definition(): ?BulkAction
    {
        return BulkActions::find($this->action);
    }

    public function actionLabel(): string
    {
        return $this->definition()?->label() ?? $this->action;
    }

    public function isActive(): bool
    {
        return $this->status === 'running';
    }

    /** @return array{pending:int, running:int, succeeded:int, attention:int, failed:int, skipped:int, cancelled:int, done:int, total:int, percent:int} */
    public function counts(): array
    {
        $by = $this->items()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $c = [];
        foreach (['pending', 'running', 'succeeded', 'attention', 'failed', 'skipped', 'cancelled'] as $s) {
            $c[$s] = $by[$s] ?? 0;
        }
        $c['total'] = array_sum($c);
        $c['done'] = $c['succeeded'] + $c['attention'] + $c['failed'] + $c['skipped'] + $c['cancelled'];
        $c['percent'] = $c['total'] ? (int) floor($c['done'] * 100 / $c['total']) : 100;

        return $c;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'running' => __('Running'),
            'paused' => __('Paused'),
            'cancelled' => __('Cancelled'),
            'finished' => __('Finished'),
            default => $this->status,
        };
    }
}
