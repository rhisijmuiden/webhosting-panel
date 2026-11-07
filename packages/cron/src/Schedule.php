<?php

declare(strict_types=1);

namespace Rhis\Cron;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Cron expressions: the same five field syntax the server agent turns into a
 * systemd timer (numbers, *, ranges, steps, lists, jan-dec, sun-sat, @daily ...).
 * Also gives the next runs and a description in words for the screens.
 */
final class Schedule
{
    public const MACROS = [
        '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *',
    ];

    private const FIELDS = [
        ['minute', 0, 59, null],
        ['hour', 0, 23, null],
        ['day of month', 1, 31, null],
        ['month', 1, 12, ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']],
        ['day of week', 0, 7, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']],
    ];

    /** Choices in the form: [expression => label]. Labels are translated in the view. */
    public static function presets(): array
    {
        return [
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '0 * * * *' => 'Every hour',
            '0 3 * * *' => 'Every day at 03:00',
            '0 3 * * 1' => 'Every Monday at 03:00',
            '0 3 1 * *' => 'On the 1st of every month at 03:00',
        ];
    }

    /** "*\/5  *  * * *" -> "*\/5 * * * *", "@daily" -> "0 0 * * *". */
    public static function normalize(string $expr): string
    {
        $expr = trim((string) preg_replace('/\s+/', ' ', $expr));

        return self::MACROS[strtolower($expr)] ?? $expr;
    }

    /** null when valid, otherwise the problem (English, translate with __()). */
    public static function error(string $expr): ?string
    {
        $parts = explode(' ', self::normalize($expr));
        if (count($parts) !== 5) {
            return 'The schedule needs five fields: minute, hour, day of the month, month and day of the week.';
        }
        foreach ($parts as $i => $part) {
            if (self::expand($part, self::FIELDS[$i]) === null) {
                return 'The :field in the schedule is not valid.';
            }
        }

        return null;
    }

    /** The field the error is about, for the message. */
    public static function errorField(string $expr): ?string
    {
        $parts = explode(' ', self::normalize($expr));
        foreach (count($parts) === 5 ? $parts : [] as $i => $part) {
            if (self::expand($part, self::FIELDS[$i]) === null) {
                return self::FIELDS[$i][0];
            }
        }

        return null;
    }

    /** @return list<int>|null */
    private static function expand(string $text, array $field): ?array
    {
        [$name, $lo, $hi, $names] = $field;
        $value = function (string $t) use ($lo, $hi, $names, $name): ?int {
            $t = strtolower($t);
            if ($names && in_array($t, $names, true)) {
                return array_search($t, $names, true) + ($name === 'month' ? 1 : 0);
            }
            if (! preg_match('/^\d{1,2}$/', $t) || (int) $t < $lo || (int) $t > $hi) {
                return null;
            }

            return (int) $t;
        };
        $out = [];
        foreach (explode(',', $text) as $part) {
            if ($part === '') {
                return null;
            }
            [$range, $stepText] = array_pad(explode('/', $part, 2), 2, null);
            $step = 1;
            if ($stepText !== null) {
                if (! preg_match('/^\d{1,2}$/', $stepText) || (int) $stepText < 1 || (int) $stepText > $hi) {
                    return null;
                }
                $step = (int) $stepText;
            }
            if ($range === '*') {
                [$start, $end] = [$lo, $hi];
            } elseif (str_contains($range, '-')) {
                [$a, $b] = explode('-', $range, 2);
                [$start, $end] = [$value($a), $value($b)];
                if ($start === null || $end === null || $start > $end) {
                    return null;
                }
            } else {
                $start = $value($range);
                if ($start === null) {
                    return null;
                }
                $end = $stepText !== null ? $hi : $start;
            }
            for ($n = $start; $n <= $end; $n += $step) {
                $out[$n] = true;
            }
        }

        return array_keys($out);
    }

    private static function cron(string $expr): ?CronExpression
    {
        try {
            // Day of week 7 is Sunday too; the library wants 0.
            $parts = explode(' ', self::normalize($expr));
            $parts[4] = preg_replace('/(?<![0-9])7(?![0-9])/', '0', $parts[4] ?? '*');

            return new CronExpression(implode(' ', $parts));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<Carbon> */
    public static function nextRuns(string $expr, int $count = 3, ?string $tz = null): array
    {
        if (self::error($expr) !== null || ! ($cron = self::cron($expr))) {
            return [];
        }
        $tz ??= (string) config('app.timezone');
        try {
            $dates = $cron->getMultipleRunDates($count, 'now', false, false, $tz);
        } catch (Throwable) {
            return [];
        }

        return array_map(fn ($d) => Carbon::instance($d)->setTimezone(new DateTimeZone($tz)), $dates);
    }

    /** Shortest time between two runs in the coming days (minutes), null when it cannot be computed. */
    public static function shortestGap(string $expr): ?int
    {
        $runs = self::nextRuns($expr, 60);
        if (count($runs) < 2) {
            return null;
        }
        $gap = PHP_INT_MAX;
        for ($i = 1; $i < count($runs); $i++) {
            $gap = min($gap, (int) round($runs[$i - 1]->diffInMinutes($runs[$i], true)));
        }

        return $gap;
    }

    /** The schedule in words ("Every day at 03:00"), or null for anything unusual. */
    public static function describe(string $expr): ?string
    {
        if (self::error($expr) !== null) {
            return null;
        }
        [$m, $h, $dom, $mon, $dow] = explode(' ', self::normalize($expr));
        $num = fn ($v) => preg_match('/^\d{1,2}$/', $v) === 1;
        $at = fn () => sprintf('%02d:%02d', (int) $h, (int) $m);
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        if ($mon !== '*') {
            return null;
        }
        if ($h === '*' && $dom === '*' && $dow === '*') {
            if ($m === '*') {
                return __('Every minute');
            }
            if (preg_match('#^\*/(\d+)$#', $m, $x)) {
                return (int) $x[1] === 1 ? __('Every minute') : __('Every :n minutes', ['n' => (int) $x[1]]);
            }
            if ($num($m)) {
                return __('Every hour at :minute minutes past', ['minute' => (int) $m]);
            }

            return null;
        }
        if (! $num($m)) {
            return null;
        }
        if ($dom === '*' && $dow === '*' && preg_match('#^\*/(\d+)$#', $h, $x)) {
            return __('Every :n hours at :minute minutes past', ['n' => (int) $x[1], 'minute' => (int) $m]);
        }
        if (! $num($h)) {
            return null;
        }
        if ($dom === '*' && $dow === '*') {
            return __('Every day at :time', ['time' => $at()]);
        }
        if ($dom === '*' && $num($dow)) {
            return __('Every :day at :time', ['day' => __($days[(int) $dow]), 'time' => $at()]);
        }
        if ($dom === '*' && $dow === '1-5') {
            return __('Monday to Friday at :time', ['time' => $at()]);
        }
        if ($dow === '*' && $num($dom)) {
            return __('Monthly on day :day at :time', ['day' => (int) $dom, 'time' => $at()]);
        }

        return null;
    }
}
