<?php

declare(strict_types=1);

namespace Rhis\Backups\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One automatic restore test of the server backups.
 *
 * @property array|null $report whp-backup verify report: {status, targets: [{target, status, items, coverage}]}
 */
class RestoreTest extends Model
{
    protected $table = 'backup_restore_tests';

    protected $guarded = ['id'];

    protected $casts = [
        'report' => 'array', 'slot_at' => 'datetime', 'started_at' => 'datetime',
        'finished_at' => 'datetime', 'notified_at' => 'datetime',
    ];

    public const FINISHED = ['ok', 'warning', 'failed', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isProblem(): bool
    {
        return in_array($this->status, ['failed', 'error'], true);
    }

    /** Pill tone for the status. */
    public function tone(): string
    {
        return match ($this->status) {
            'ok' => 'ok', 'warning', 'busy', 'running' => 'warn', 'failed', 'error' => 'bad', default => 'neutral',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'ok' => __('Passed'), 'warning' => __('Passed with warnings'), 'failed' => __('Failed'),
            'error' => __('Could not run'), 'busy' => __('Postponed'), 'running' => __('Running'), default => $this->status,
        };
    }

    /** Every tested restore point of the report, with its location. */
    public function items(): array
    {
        $out = [];
        foreach ($this->report['targets'] ?? [] as $t) {
            foreach ($t['items'] ?? [] as $item) {
                $out[] = $item + ['target' => $t['target'] ?? ''];
            }
        }

        return $out;
    }

    /** Numbers over all locations: items, with_backup, missing[], stale[]. */
    public function coverage(): array
    {
        $c = ['items' => 0, 'with_backup' => 0, 'missing' => [], 'stale' => []];
        foreach ($this->report['targets'] ?? [] as $t) {
            $cov = $t['coverage'] ?? null;
            if (! is_array($cov)) {
                continue;
            }
            $c['items'] += (int) ($cov['items'] ?? 0);
            $c['with_backup'] += (int) ($cov['with_backup'] ?? 0);
            foreach ($cov['missing'] ?? [] as $m) {
                $c['missing'][] = ['target' => $t['target'], 'scope' => $m];
            }
            foreach ($cov['stale'] ?? [] as $s) {
                $c['stale'][] = ['target' => $t['target']] + $s;
            }
        }

        return $c;
    }

    public function checksPassed(): array
    {
        $ok = 0;
        $all = 0;
        foreach ($this->items() as $item) {
            foreach ($item['checks'] ?? [] as $check) {
                $all++;
                $ok += ($check['result'] ?? '') === 'ok' ? 1 : 0;
            }
        }

        return [$ok, $all];
    }

    public function durationForHumans(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return $this->started_at->diffAsCarbonInterval($this->finished_at)->forHumans(['short' => true, 'parts' => 2]);
    }
}
