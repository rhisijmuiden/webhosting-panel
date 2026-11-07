<?php

declare(strict_types=1);

namespace Rhis\Cron\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rhis\Cron\Schedule;

/**
 * A scheduled task of a website: a systemd timer on the server
 * (whp-cron-<uid>.timer) that runs as the website's own user.
 */
class CronJob extends Model
{
    public const TYPES = ['command' => 'Command', 'php' => 'PHP script', 'url' => 'Open a URL'];

    /** Longest run time to pick from (minutes => label). */
    public const TIMEOUTS = [1 => '1 min', 5 => '5 min', 15 => '15 min', 30 => '30 min', 60 => '1 hour', 120 => '2 hours',
        240 => '4 hours', 480 => '8 hours', 720 => '12 hours', 1440 => '24 hours'];

    protected $table = 'cron_jobs';

    protected $fillable = [
        'website_id', 'uid', 'name', 'type', 'command', 'args', 'schedule', 'timeout', 'enabled',
        'notify_failure', 'notify_email', 'last_run_at', 'last_status', 'last_duration_ms', 'failures',
        'notified_at', 'last_error',
    ];

    protected $attributes = ['type' => 'command', 'timeout' => 60, 'enabled' => true, 'notify_failure' => true, 'failures' => 0];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'notify_failure' => 'boolean', 'timeout' => 'integer', 'failures' => 'integer',
            'last_run_at' => 'datetime', 'notified_at' => 'datetime', 'last_duration_ms' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CronRun::class)->orderByDesc('finished_at')->orderByDesc('id');
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('website', fn ($w) => $w->where('customer_id', $customerId));
    }

    /** What runs, in one line for lists. */
    public function summary(): string
    {
        return match ($this->type) {
            'php' => 'php '.$this->command.($this->args ? ' '.$this->args : ''),
            default => $this->command,
        };
    }

    public function scheduleLabel(): string
    {
        return Schedule::describe($this->schedule) ?? $this->schedule;
    }

    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type] ?? $this->type);
    }

    public static function newUid(): string
    {
        do {
            $uid = bin2hex(random_bytes(5));
        } while (self::withoutGlobalScopes()->where('uid', $uid)->exists());

        return $uid;
    }
}
