<?php

namespace App\Services;

use App\Support\StatsRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data for the graphs and rankings of the admin Statistics page, in the shape
 * the graph script (public/assets/statistics.js, uPlot) reads:
 * ['x' => [epoch, ...], 'unit' => percent|bytes|bps|count|number, 'series' => [['label', 'values', 'color'?]]].
 */
class StatisticsData
{
    /** Graphs of the whole server: source table, unit, max and series (column => [label, aggregate]). */
    public const SERVER = [
        'cpu' => ['source' => 'metrics', 'unit' => 'percent', 'max' => 100, 'series' => ['cpu_percent' => ['CPU', 'avg']]],
        'load' => ['source' => 'metrics', 'unit' => 'number', 'series' => ['load1' => ['Load (1 minute)', 'avg']]],
        'memory' => ['source' => 'metrics', 'unit' => 'bytes', 'series' => ['memory_used' => ['Memory in use', 'avg'], 'swap_used' => ['Swap in use', 'avg']]],
        'disk' => ['source' => 'metrics', 'unit' => 'bytes', 'series' => ['disk_used' => ['Disk in use', 'max'], 'disk_total' => ['Disk size', 'max']]],
        'network' => ['source' => 'metrics', 'unit' => 'bps', 'series' => ['net_tx_bps' => ['Out', 'avg'], 'net_rx_bps' => ['In', 'avg']]],
        'visitors' => ['source' => 'visits', 'unit' => 'count', 'series' => ['visitors' => ['Visitors', 'sum'], 'pageviews' => ['Page views', 'sum']]],
        'requests' => ['source' => 'visits', 'unit' => 'count', 'series' => ['requests' => ['Requests', 'sum'], 'bots' => ['Of which bots', 'sum']]],
        'traffic' => ['source' => 'visits', 'unit' => 'bytes', 'series' => ['bytes' => ['Data traffic', 'sum']]],
    ];

    /** Per customer / per website: column, source and how buckets combine. */
    public const BREAKDOWN = [
        'visitors' => ['source' => 'visits', 'unit' => 'count', 'agg' => 'sum', 'label' => 'Visitors'],
        'pageviews' => ['source' => 'visits', 'unit' => 'count', 'agg' => 'sum', 'label' => 'Page views'],
        'requests' => ['source' => 'visits', 'unit' => 'count', 'agg' => 'sum', 'label' => 'Requests'],
        'bytes' => ['source' => 'visits', 'unit' => 'bytes', 'agg' => 'sum', 'label' => 'Data traffic'],
        'cpu_percent' => ['source' => 'site_metrics', 'unit' => 'percent', 'agg' => 'avg', 'label' => 'CPU'],
        'memory_bytes' => ['source' => 'site_metrics', 'unit' => 'bytes', 'agg' => 'avg', 'label' => 'Memory'],
    ];

    public const MAX_SERIES = 8;

    /** One graph of the whole server. */
    public function server(string $chart, StatsRange $range): array
    {
        $def = self::SERVER[$chart];
        [$table, $time, $resolution, $bucket] = $this->source($def['source'], $range);
        $rows = DB::table($table)->where('resolution', $resolution)
            ->whereBetween($time, [$range->from->subSeconds($bucket), $range->to])
            ->when($def['source'] === 'visits', fn ($q) => $q->whereNull('website_id'))
            ->orderBy($time)->get([$time, ...array_keys($def['series'])]);

        $x = $this->axis($range, $bucket, $rows->isEmpty() ? 0 : $this->ts($rows->first()->$time));
        $series = [];
        foreach ($def['series'] as $column => [$label, $agg]) {
            $values = $this->bucketize($rows->map(fn ($r) => [$this->ts($r->$time), $r->$column]), $bucket, $agg);
            $series[] = ['label' => __($label), 'values' => $this->fill($def['source'], $resolution, $x, $values)];
        }

        return ['x' => $x, 'unit' => $def['unit'], 'max' => $def['max'] ?? null, 'resolution' => $resolution, 'series' => $series];
    }

    /**
     * One metric split per customer or per website: the busiest ones over the
     * period (at most MAX_SERIES, the rest added up as "Other"), optionally only
     * the websites of one customer.
     */
    public function breakdown(string $metric, string $by, StatsRange $range, int $limit = self::MAX_SERIES, ?int $customerId = null): array
    {
        $def = self::BREAKDOWN[$metric];
        $limit = max(1, min(self::MAX_SERIES, $limit));
        [$table, $time, $resolution, $bucket] = $this->source($def['source'], $range);
        $key = $by === 'customer' ? 'w.customer_id' : 's.website_id';

        $base = fn () => DB::table($table.' as s')->join('websites as w', 'w.id', '=', 's.website_id')
            ->where('s.resolution', $resolution)
            ->whereBetween('s.'.$time, [$range->from->subSeconds($bucket), $range->to])
            ->when($customerId, fn ($q) => $q->where('w.customer_id', $customerId));

        $ranking = $base()->groupBy($key)->selectRaw("{$key} as k, sum(s.{$metric}) as total")
            ->orderByDesc('total')->get();
        $top = $ranking->take($limit)->pluck('k')->all();
        $labels = $by === 'customer'
            ? DB::table('customers')->whereIn('id', $top)->get(['id', 'company', 'name'])->mapWithKeys(fn ($c) => [$c->id => $c->company ?: $c->name])
            : DB::table('websites')->whereIn('id', $top)->pluck('domain', 'id');

        // Per entity and timestamp: the sum over its websites (a customer's CPU share is the sum of its sites).
        $rows = $base()->whereIn($key, $top)->groupBy($key, 's.'.$time)
            ->selectRaw("{$key} as k, s.{$time} as t, sum(s.{$metric}) as v")->get();
        $x = $this->axis($range, $bucket, $rows->isEmpty() ? 0 : $this->ts($rows->first()->t));
        $rows = $rows->groupBy('k');
        $series = [];
        foreach ($top as $i => $id) {
            $values = $this->bucketize(($rows[$id] ?? collect())->map(fn ($r) => [$this->ts($r->t), $r->v]), $bucket, $def['agg']);
            $series[] = ['id' => (int) $id, 'label' => (string) ($labels[$id] ?? '#'.$id), 'slot' => $i + 1,
                'values' => $this->fill($def['source'], $resolution, $x, $values)];
        }
        if ($ranking->count() > $limit) {
            $other = $base()->whereNotIn($key, $top)->groupBy('s.'.$time)->selectRaw("s.{$time} as t, sum(s.{$metric}) as v")->get();
            $values = $this->bucketize($other->map(fn ($r) => [$this->ts($r->t), $r->v]), $bucket, $def['agg']);
            $series[] = ['id' => null, 'label' => __('Other (:count)', ['count' => $ranking->count() - $limit]), 'slot' => 'other',
                'values' => $this->fill($def['source'], $resolution, $x, $values)];
        }

        return ['x' => $x, 'unit' => $def['unit'], 'max' => null, 'resolution' => $resolution, 'series' => $series];
    }

    /**
     * Websites ranked by load or visitors over the period: average and peak CPU,
     * memory, visitors, page views and data traffic.
     */
    public function websites(StatsRange $range, string $sort = 'cpu', int $limit = 15): Collection
    {
        $mRes = $range->metricsResolution();
        $vRes = $range->visitsResolution();
        $load = DB::table('website_metric_samples')->where('resolution', $mRes)->whereBetween('taken_at', [$range->from, $range->to])
            ->groupBy('website_id')
            ->selectRaw('website_id, avg(cpu_percent) as cpu, max(cpu_percent) as cpu_peak, avg(memory_bytes) as memory')
            ->get()->keyBy('website_id');
        $visits = DB::table('visit_stats')->whereNotNull('website_id')->where('resolution', $vRes)->whereBetween('period_start', [$range->from->subSeconds($vRes === 'day' ? 86399 : 3599), $range->to])
            ->groupBy('website_id')
            ->selectRaw('website_id, sum(visitors) as visitors, sum(pageviews) as pageviews, sum(requests) as requests, sum(bytes) as bytes')
            ->get()->keyBy('website_id');

        $ids = $load->keys()->merge($visits->keys())->unique()->all();
        $sites = DB::table('websites as w')->join('customers as c', 'c.id', '=', 'w.customer_id')->whereIn('w.id', $ids)
            ->get(['w.id', 'w.domain', 'w.customer_id', 'c.name', 'c.company']);

        $sortKey = ['cpu' => 'cpu', 'visitors' => 'visitors', 'traffic' => 'bytes', 'memory' => 'memory'][$sort] ?? 'cpu';

        return $sites->map(fn ($w) => (object) [
            'website_id' => $w->id, 'domain' => $w->domain, 'customer_id' => $w->customer_id, 'customer' => $w->company ?: $w->name,
            'cpu' => (float) ($load[$w->id]->cpu ?? 0), 'cpu_peak' => (float) ($load[$w->id]->cpu_peak ?? 0), 'memory' => (float) ($load[$w->id]->memory ?? 0),
            'visitors' => (int) ($visits[$w->id]->visitors ?? 0), 'pageviews' => (int) ($visits[$w->id]->pageviews ?? 0),
            'requests' => (int) ($visits[$w->id]->requests ?? 0), 'bytes' => (int) ($visits[$w->id]->bytes ?? 0),
        ])->sortByDesc($sortKey)->take($limit)->values();
    }

    /** The same per customer (sums over their websites). */
    public function customers(StatsRange $range, string $sort = 'cpu', int $limit = 15): Collection
    {
        $sortKey = ['cpu' => 'cpu', 'visitors' => 'visitors', 'traffic' => 'bytes', 'memory' => 'memory'][$sort] ?? 'cpu';

        return $this->websites($range, $sort, PHP_INT_MAX)->groupBy('customer_id')->map(fn ($sites) => (object) [
            'customer_id' => $sites->first()->customer_id, 'customer' => $sites->first()->customer, 'websites' => $sites->count(),
            'cpu' => $sites->sum('cpu'), 'memory' => $sites->sum('memory'), 'visitors' => $sites->sum('visitors'),
            'pageviews' => $sites->sum('pageviews'), 'bytes' => $sites->sum('bytes'),
        ])->sortByDesc($sortKey)->take($limit)->values();
    }

    /** Totals of the whole server for the period (visitors summed per day or hour). */
    public function totals(StatsRange $range): array
    {
        $vRes = $range->visitsResolution();
        $row = DB::table('visit_stats')->whereNull('website_id')->where('resolution', $vRes)
            ->whereBetween('period_start', [$range->from->subSeconds($vRes === 'day' ? 86399 : 3599), $range->to])
            ->selectRaw('sum(visitors) as visitors, sum(pageviews) as pageviews, sum(requests) as requests, sum(bots) as bots, sum(bytes) as bytes')->first();
        $today = DB::table('visit_stats')->whereNull('website_id')->where('resolution', 'day')->orderByDesc('period_start')->first();

        return [
            'visitors' => (int) ($row->visitors ?? 0), 'pageviews' => (int) ($row->pageviews ?? 0), 'requests' => (int) ($row->requests ?? 0),
            'bots' => (int) ($row->bots ?? 0), 'bytes' => (int) ($row->bytes ?? 0),
            'visitors_today' => $today && $this->ts($today->period_start) > time() - 86400 ? (int) $today->visitors : 0,
            'resolution' => $vRes,
        ];
    }

    /** [table, time column, resolution, bucket seconds] for a source. */
    private function source(string $source, StatsRange $range): array
    {
        if ($source === 'visits') {
            $resolution = $range->visitsResolution();

            // Unique visitors of two hours cannot be added up: visits stay at their own resolution.
            return ['visit_stats', 'period_start', $resolution, $resolution === 'day' ? 86400 : 3600];
        }
        $resolution = $range->metricsResolution();
        // The rollup writes one row per hour/day: never bucket finer than that.

        return [$source === 'metrics' ? 'metric_samples' : 'website_metric_samples', 'taken_at', $resolution, $range->bucket($resolution)];
    }

    /**
     * Every bucket start of the period. Day rows start at a midnight that need not
     * be UTC (visits use the server's local day), so days align on a sample.
     */
    private function axis(StatsRange $range, int $bucket, int $sample = 0): array
    {
        $offset = $bucket === 86400 ? $sample % 86400 : 0;
        $start = intdiv($range->from->getTimestamp() - $offset, $bucket) * $bucket + $offset;
        $x = [];
        for ($t = $start; $t <= $range->to->getTimestamp(); $t += $bucket) {
            $x[] = $t;
        }

        return $x;
    }

    /**
     * Values on the axis. A missing sample of the server is a gap (nothing was
     * measured); a missing visits bucket while visits were being counted is 0.
     */
    private function fill(string $source, string $resolution, array $x, array $values): array
    {
        if ($source !== 'visits') {
            return array_map(fn ($t) => $values[$t] ?? null, $x);
        }
        $this->visitSpan[$resolution] ??= (function () use ($resolution) {
            $row = DB::table('visit_stats')->where('resolution', $resolution)->selectRaw('min(period_start) as a, max(period_start) as b')->first();

            return $row && $row->a ? [$this->ts($row->a), $this->ts($row->b)] : [PHP_INT_MAX, 0];
        })();
        [$first, $last] = $this->visitSpan[$resolution];

        return array_map(fn ($t) => $values[$t] ?? ($t >= $first && $t <= $last ? 0 : null), $x);
    }

    /** @var array<string, array{0:int,1:int}> */
    private array $visitSpan = [];

    /** @param Collection<int, array{0:int,1:mixed}> $points */
    private function bucketize(Collection $points, int $bucket, string $agg): array
    {
        $offset = $bucket === 86400 && $points->isNotEmpty() ? $points->first()[0] % 86400 : 0;
        $groups = [];
        foreach ($points as [$t, $v]) {
            if ($v === null) {
                continue;
            }
            $groups[intdiv($t - $offset, $bucket) * $bucket + $offset][] = (float) $v;
        }
        $out = [];
        foreach ($groups as $t => $vals) {
            $value = match ($agg) {
                'sum' => array_sum($vals),
                'max' => max($vals),
                default => array_sum($vals) / count($vals),
            };
            $out[$t] = round($value, 2);
        }

        return $out;
    }

    private function ts(string $value): int
    {
        return CarbonImmutable::parse($value, config('app.timezone'))->getTimestamp();
    }
}
