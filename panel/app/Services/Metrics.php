<?php

namespace App\Services;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server and per-site usage history: collect one sample a minute from the
 * agent, roll minutes up into hours and hours into days, and read series back
 * for the statistics page.
 */
class Metrics
{
    public const RANGES = [
        '24h' => ['hours' => 24, 'resolution' => 'minute', 'bucket' => 300],
        '7d' => ['hours' => 168, 'resolution' => 'hour', 'bucket' => 3600],
        '30d' => ['hours' => 720, 'resolution' => 'hour', 'bucket' => 3 * 3600],
        '1y' => ['hours' => 8760, 'resolution' => 'day', 'bucket' => 86400],
    ];

    private const SERVER_AVG = ['cpu_percent', 'load1', 'memory_used', 'swap_used', 'net_rx_bps', 'net_tx_bps'];

    private const SERVER_LAST = ['memory_total', 'disk_used', 'disk_total'];

    private const SITE_AVG = ['cpu_percent', 'memory_bytes', 'io_read_bps', 'io_write_bps'];

    public function __construct(private readonly AgentClient $agent) {}

    /** Take one sample. Returns false when it was the agent's first sample (no rates yet). */
    public function collect(?CarbonImmutable $at = null): bool
    {
        $data = $this->agent->call('stats.sample', [], 20);
        if ($data['first_sample'] ?? false) {
            return false;
        }
        $at = ($at ?? CarbonImmutable::now())->startOfMinute();

        DB::table('metric_samples')->upsert([array_merge(
            ['resolution' => 'minute', 'taken_at' => $at],
            collect($data)->only([...self::SERVER_AVG, ...self::SERVER_LAST])->all(),
        )], ['resolution', 'taken_at']);

        $sites = Website::pluck('id', 'domain')->mapWithKeys(fn ($id, $domain) => [self::siteId($domain) => $id]);
        $rows = [];
        foreach ($data['sites'] ?? [] as $site) {
            if ($id = $sites[$site['site_id']] ?? null) {
                $rows[] = ['website_id' => $id, 'resolution' => 'minute', 'taken_at' => $at] + collect($site)->only(self::SITE_AVG)->all();
            }
        }
        if ($rows) {
            DB::table('website_metric_samples')->insert($rows);
        }

        return true;
    }

    /** The id whp-site uses in unit names: the domain with . and - as _. */
    public static function siteId(string $domain): string
    {
        return str_replace(['.', '-'], '_', $domain);
    }

    /** Roll finished hours and days up, and drop detail that is no longer needed. */
    public function rollup(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $this->rollupLevel('minute', 'hour', $now->startOfHour(), fn (CarbonImmutable $t) => $t->startOfHour());
        $this->rollupLevel('hour', 'day', $now->startOfDay(), fn (CarbonImmutable $t) => $t->startOfDay());

        foreach (['metric_samples', 'website_metric_samples'] as $table) {
            DB::table($table)->where('resolution', 'minute')->where('taken_at', '<', $now->subHours(48))->delete();
            DB::table($table)->where('resolution', 'hour')->where('taken_at', '<', $now->subDays(90))->delete();
        }
    }

    private function rollupLevel(string $from, string $to, CarbonImmutable $until, \Closure $period): void
    {
        $last = DB::table('metric_samples')->where('resolution', $to)->max('taken_at');
        $since = $last ? $period(CarbonImmutable::parse($last))->add($to === 'hour' ? '1 hour' : '1 day') : null;

        $server = DB::table('metric_samples')->where('resolution', $from)->where('taken_at', '<', $until)
            ->when($since, fn ($q) => $q->where('taken_at', '>=', $since))->get();
        foreach ($server->groupBy(fn ($r) => $period(CarbonImmutable::parse($r->taken_at))->toDateTimeString()) as $at => $rows) {
            $row = ['resolution' => $to, 'taken_at' => $at];
            foreach (self::SERVER_AVG as $f) {
                $row[$f] = $rows->avg($f);
            }
            foreach (self::SERVER_LAST as $f) {
                $row[$f] = $rows->max($f);
            }
            DB::table('metric_samples')->upsert([$row], ['resolution', 'taken_at']);
        }

        $sites = DB::table('website_metric_samples')->where('resolution', $from)->where('taken_at', '<', $until)
            ->when($since, fn ($q) => $q->where('taken_at', '>=', $since))->get();
        $insert = [];
        foreach ($sites->groupBy(fn ($r) => $r->website_id.'|'.$period(CarbonImmutable::parse($r->taken_at))->toDateTimeString()) as $key => $rows) {
            [$websiteId, $at] = explode('|', $key);
            $row = ['website_id' => (int) $websiteId, 'resolution' => $to, 'taken_at' => $at];
            foreach (self::SITE_AVG as $f) {
                $row[$f] = $rows->avg($f);
            }
            $insert[] = $row;
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('website_metric_samples')->insert($chunk);
        }
    }

    /** Server series for a range, averaged into evenly sized buckets. */
    public function serverSeries(string $range): Collection
    {
        $r = self::RANGES[$range];
        $from = CarbonImmutable::now()->subHours($r['hours']);
        $rows = DB::table('metric_samples')->where('resolution', $r['resolution'])->where('taken_at', '>=', $from)->orderBy('taken_at')->get();

        return $rows->groupBy(fn ($row) => intdiv(CarbonImmutable::parse($row->taken_at)->getTimestamp(), $r['bucket']) * $r['bucket'])
            ->map(fn ($g, $ts) => [
                't' => (int) $ts,
                'cpu' => round($g->avg('cpu_percent'), 1),
                'load' => round($g->avg('load1'), 2),
                'memory' => $g->max('memory_total') ? round($g->avg('memory_used') / $g->max('memory_total') * 100, 1) : 0,
                'memory_used' => (int) $g->avg('memory_used'),
                'memory_total' => (int) $g->max('memory_total'),
                'disk' => $g->max('disk_total') ? round($g->max('disk_used') / $g->max('disk_total') * 100, 1) : 0,
                'disk_used' => (int) $g->max('disk_used'),
                'rx' => (int) $g->avg('net_rx_bps'),
                'tx' => (int) $g->avg('net_tx_bps'),
            ])->values();
    }

    /** Websites ranked by average CPU over the range, with their customer. */
    public function topWebsites(string $range, int $limit = 10): Collection
    {
        $r = self::RANGES[$range];
        $from = CarbonImmutable::now()->subHours($r['hours']);

        return DB::table('website_metric_samples as s')
            ->join('websites as w', 'w.id', '=', 's.website_id')
            ->join('customers as c', 'c.id', '=', 'w.customer_id')
            ->where('s.resolution', $r['resolution'])->where('s.taken_at', '>=', $from)
            ->groupBy('s.website_id', 'w.domain', 'w.customer_id', 'c.name', 'c.company')
            ->selectRaw('s.website_id, w.domain, w.customer_id, c.name, c.company, avg(s.cpu_percent) as cpu, max(s.cpu_percent) as cpu_peak, avg(s.memory_bytes) as memory, avg(s.io_read_bps + s.io_write_bps) as io')
            ->orderByDesc('cpu')->limit($limit)->get();
    }

    /** Customers ranked by the summed average CPU of their websites. */
    public function topCustomers(string $range, int $limit = 10): Collection
    {
        return $this->topWebsites($range, 1000)
            ->groupBy('customer_id')
            ->map(fn ($sites) => (object) [
                'customer_id' => $sites->first()->customer_id,
                'name' => $sites->first()->company ?: $sites->first()->name,
                'cpu' => $sites->sum('cpu'),
                'memory' => $sites->sum('memory'),
                'websites' => $sites->count(),
            ])->sortByDesc('cpu')->take($limit)->values();
    }
}
