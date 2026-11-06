<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use App\Models\Customer;
use App\Models\Website;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Rhis\Monitoring\Models\Daily;
use Rhis\Monitoring\Models\Incident;

/** Uptime numbers from the daily totals. */
final class Uptime
{
    /**
     * One entry per day, oldest first: [day, checks, up, uptime, down_minutes, avg_ms].
     *
     * @return list<array{day: string, checks: int, uptime: ?float, down_minutes: int, avg_ms: ?int}>
     */
    public static function days(string $key, int $days = 90): array
    {
        $tz = (string) config('app.timezone');
        $from = Carbon::now($tz)->subDays($days - 1)->startOfDay();
        $rows = Daily::where('key', $key)->where('day', '>=', $from->toDateString())->get()->keyBy(fn (Daily $d) => substr((string) $d->day, 0, 10));
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $r = $rows->get($day);
            $out[] = [
                'day' => $day,
                'checks' => (int) ($r->checks ?? 0),
                'up' => (int) ($r->up ?? 0),
                'uptime' => $r?->uptime(),
                'down_minutes' => intdiv((int) ($r->down_seconds ?? 0), 60),
                'avg_ms' => $r && $r->ms_count ? intdiv((int) $r->ms_total, (int) $r->ms_count) : null,
            ];
        }

        return $out;
    }

    public static function percent(array $days): ?float
    {
        $checks = array_sum(array_column($days, 'checks'));

        return $checks ? round(array_sum(array_column($days, 'up')) * 100 / $checks, 2) : null;
    }

    /** Uptime of one website between two moments (whole days, in the panel's timezone). */
    public static function between(string $key, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = (string) config('app.timezone');
        $start = Carbon::instance($from)->timezone($tz);
        $end = Carbon::instance($to)->timezone($tz);
        // $to at midnight is exclusive (1 Nov - 1 Dec), any other time includes that day (1 Nov - 30 Nov 23:59).
        $endDay = $end->equalTo($end->copy()->startOfDay()) ? $end->toDateString() : $end->copy()->addDay()->toDateString();
        $rows = Daily::where('key', $key)->where('day', '>=', $start->toDateString())->where('day', '<', $endDay)
            ->get();
        $checks = (int) $rows->sum('checks');
        $msCount = (int) $rows->sum('ms_count');

        return [
            'checks' => $checks,
            'uptime' => $checks ? round($rows->sum('up') * 100 / $checks, 3) : null,
            'down_seconds' => (int) $rows->sum('down_seconds'),
            'response_ms' => $msCount ? intdiv((int) $rows->sum('ms_total'), $msCount) : null,
        ];
    }

    /**
     * The numbers for the monthly report of one customer (Rhis\Reports 'uptime' provider).
     *
     * @return array{sites: array<string, array{uptime: float, downtime_minutes: int, incidents: int, response_ms: ?int}>}|null
     */
    public static function report(Customer $customer, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $sites = [];
        foreach (Website::where('customer_id', $customer->id)->orderBy('domain')->get(['id', 'domain']) as $w) {
            $u = self::between("site:{$w->id}", $from, $to);
            if (! $u['checks']) {
                continue;
            }
            $incidents = Incident::where('key', "site:{$w->id}")->between($from, $to)->get();
            $downtime = $incidents->sum(fn (Incident $i) => $i->durationSeconds($from, $to));
            $sites[$w->domain] = [
                'uptime' => $u['uptime'],
                'downtime_minutes' => (int) round(max($downtime, 0) / 60),
                'incidents' => $incidents->count(),
                'response_ms' => $u['response_ms'],
            ];
        }

        return $sites ? ['sites' => $sites] : null;
    }
}
