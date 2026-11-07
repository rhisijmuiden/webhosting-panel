<?php

declare(strict_types=1);

namespace Rhis\Cron\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of a cron job, as the server saved it. */
class CronRun extends Model
{
    /** status => [pill status, label] */
    public const STATUSES = [
        'success' => ['ok', 'Succeeded'],
        'failed' => ['bad', 'Failed'],
        'timeout' => ['bad', 'Took too long'],
        'oom' => ['bad', 'Out of memory'],
        'stopped' => ['warn', 'Stopped'],
    ];

    protected $table = 'cron_runs';

    protected $fillable = [
        'cron_job_id', 'invocation', 'status', 'exit_status', 'signal', 'started_at', 'finished_at', 'duration_ms', 'output', 'truncated',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'truncated' => 'boolean', 'exit_status' => 'integer', 'duration_ms' => 'integer'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CronJob::class, 'cron_job_id');
    }

    public function failed(): bool
    {
        return $this->status !== 'success' && $this->status !== 'stopped';
    }

    /** Why it failed, in words ("Exit code 1", "Took longer than 15 min"). */
    public function reason(): ?string
    {
        return match ($this->status) {
            'success' => null,
            'timeout' => __('Took longer than the time limit and was stopped'),
            'oom' => __('Stopped: the website ran out of memory'),
            'stopped' => __('Stopped by hand'),
            default => $this->signal ? __('Stopped by signal :signal', ['signal' => $this->signal])
                : __('Exit code :code', ['code' => $this->exit_status ?? '?']),
        };
    }

    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '–';
        }
        if ($ms < 1000) {
            return $ms.' ms';
        }
        if ($ms < 60_000) {
            return rtrim(rtrim(number_format($ms / 1000, 1, '.', ''), '0'), '.').' s';
        }

        return \Carbon\CarbonInterval::seconds(intdiv($ms, 1000))->cascade()->forHumans(short: true, parts: 2);
    }
}
