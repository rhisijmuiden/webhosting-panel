<?php

namespace Database\Seeders;

use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Two days of minute samples and a year of hourly/daily history, for local development. */
class DemoMetricsSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $sites = Website::pluck('id')->values();
        $gen = function (CarbonImmutable $t, string $res) use ($sites) {
            $day = 0.5 + 0.5 * sin(((int) $t->format('G') + $t->minute / 60 - 8) / 24 * 2 * M_PI);
            $noise = mt_rand(0, 100) / 100;
            $server = ['resolution' => $res, 'taken_at' => $t, 'cpu_percent' => round(6 + 34 * $day + 8 * $noise, 1), 'load1' => round(0.3 + 2.5 * $day, 2),
                'memory_used' => (int) ((5 + 3.5 * $day + $noise) * 1024 ** 3), 'memory_total' => 16 * 1024 ** 3, 'swap_used' => 0,
                'disk_used' => (int) ((110 + $t->diffInDays(CarbonImmutable::now()->subYear()) * 0.03) * 1024 ** 3), 'disk_total' => 400 * 1024 ** 3,
                'net_rx_bps' => (int) ((150 + 900 * $day * (0.6 + $noise)) * 1024), 'net_tx_bps' => (int) ((500 + 2600 * $day * (0.6 + $noise)) * 1024)];
            $rows = $sites->map(fn ($id, $i) => ['website_id' => $id, 'resolution' => $res, 'taken_at' => $t,
                'cpu_percent' => round((14 / ($i + 1)) * $day * (0.5 + $noise), 2), 'memory_bytes' => (int) ((320 / ($i + 1)) * 1024 ** 2),
                'io_read_bps' => 20000, 'io_write_bps' => 8000])->all();

            return [$server, $rows];
        };

        foreach ([['minute', 48 * 60, 'subMinutes'], ['hour', 90 * 24, 'subHours'], ['day', 365, 'subDays']] as [$res, $count, $sub]) {
            $servers = [];
            $siteRows = [];
            for ($i = $count; $i >= 0; $i--) {
                [$s, $r] = $gen($now->$sub($i), $res);
                $servers[] = $s;
                array_push($siteRows, ...$r);
            }
            foreach (array_chunk($servers, 500) as $c) {
                DB::table('metric_samples')->insert($c);
            }
            foreach (array_chunk($siteRows, 500) as $c) {
                DB::table('website_metric_samples')->insert($c);
            }
        }
    }
}
