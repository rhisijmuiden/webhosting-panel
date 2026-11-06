<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Rhis\ServerAdmin\Options;

/** A scheduled maintenance task (database optimize, cleanup, ...) with its schedule. */
class ServerTask extends Model
{
    public const FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly'];

    protected $fillable = ['key', 'enabled', 'frequency', 'time', 'weekday', 'day', 'options', 'next_run_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'options' => 'array', 'next_run_at' => 'datetime', 'weekday' => 'integer', 'day' => 'integer'];
    }

    /** Every known task, created with its defaults the first time. */
    public static function synced(): \Illuminate\Support\Collection
    {
        $existing = static::all()->keyBy('key');
        foreach ((array) config('server-admin.tasks') as $key => $d) {
            if (! $existing->has($key)) {
                $task = new static([
                    'key' => $key, 'enabled' => $d['enabled'] ?? true, 'frequency' => $d['frequency'] ?? 'daily',
                    'time' => $d['time'] ?? '04:00', 'weekday' => $d['weekday'] ?? 0, 'day' => $d['day'] ?? 1,
                    'options' => $d['options'] ?? [],
                ]);
                $task->next_run_at = $task->nextRun();
                $task->save();
                $existing[$key] = $task;
            }
        }

        // A plain collection: Eloquent's only() filters on primary keys, not on array keys.
        $order = array_keys((array) config('server-admin.tasks'));

        return collect($existing->all())->only($order)->sortBy(fn ($t) => array_search($t->key, $order, true));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? config("server-admin.tasks.{$this->key}.options.$key", $default);
    }

    /** The first moment after $from that matches the schedule (in the server's time zone), as UTC. */
    public function nextRun(?Carbon $from = null): Carbon
    {
        $tz = app(Options::class)->timezone();
        $from = ($from ?? Carbon::now())->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', $this->time ?: '04:00') + [0, 0]);

        $next = match ($this->frequency) {
            'hourly' => $from->copy()->setTime($from->hour, $m),
            'weekly' => $from->copy()->setTime($h, $m)->addDays(($this->weekday - $from->dayOfWeek + 7) % 7),
            'monthly' => $from->copy()->setDay(max(1, min(28, $this->day)))->setTime($h, $m),
            default => $from->copy()->setTime($h, $m),
        };
        while ($next->lessThanOrEqualTo($from)) {
            $next = match ($this->frequency) {
                'hourly' => $next->addHour(),
                'weekly' => $next->addWeek(),
                'monthly' => $next->addMonthNoOverflow(),
                default => $next->addDay(),
            };
        }

        return $next->setTimezone('UTC');
    }

    public function lastRun(): ?ServerTaskRun
    {
        return ServerTaskRun::where('task', $this->key)->latest('id')->first();
    }

    public function scheduleLabel(): string
    {
        $days = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];

        return match ($this->frequency) {
            'hourly' => __('Every hour at :min minutes past', ['min' => substr($this->time, 3, 2)]),
            'weekly' => __('Every :day at :time', ['day' => $days[$this->weekday] ?? '', 'time' => $this->time]),
            'monthly' => __('Every month on day :day at :time', ['day' => $this->day, 'time' => $this->time]),
            default => __('Every day at :time', ['time' => $this->time]),
        };
    }
}
