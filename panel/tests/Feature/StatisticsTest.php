<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Website;
use App\Services\Metrics;
use App\Services\StatisticsData;
use App\Services\Visits;
use App\Support\StatsRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private function sample(float $cpu, array $sites = []): array
    {
        return ['first_sample' => false, 'cpu_percent' => $cpu, 'load1' => 1.0, 'memory_used' => 4 * 1024 ** 3, 'memory_total' => 16 * 1024 ** 3,
            'swap_used' => 0, 'disk_used' => 100, 'disk_total' => 400, 'net_rx_bps' => 1000, 'net_tx_bps' => 2000, 'sites' => $sites];
    }

    public function test_collect_maps_sites_and_rollup_averages(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'my-shop.nl', 'status' => 'active']);
        $metrics = app(Metrics::class);
        $start = CarbonImmutable::parse('2026-11-05 10:00:00');

        foreach ([10, 30] as $i => $cpu) {
            $this->agent()->respond('stats.sample', $this->sample($cpu, [
                ['site_id' => 'my_shop_nl', 'cpu_percent' => $cpu / 2, 'memory_bytes' => 100, 'io_read_bps' => 1, 'io_write_bps' => 1],
                ['site_id' => 'unknown_nl', 'cpu_percent' => 99, 'memory_bytes' => 1, 'io_read_bps' => 1, 'io_write_bps' => 1],
            ]));
            $this->assertTrue($metrics->collect($start->addMinutes($i)));
        }
        $this->assertSame(2, DB::table('website_metric_samples')->count(), 'Unknown sites are ignored');

        $metrics->rollup($start->addHours(2));
        $hour = DB::table('metric_samples')->where('resolution', 'hour')->first();
        $this->assertEquals(20, $hour->cpu_percent);
        $this->assertEquals(10, DB::table('website_metric_samples')->where('resolution', 'hour')->value('cpu_percent'));

        // Running it again does not double the hour.
        $metrics->rollup($start->addHours(2));
        $this->assertSame(1, DB::table('website_metric_samples')->where('resolution', 'hour')->count());

        // Minute detail older than 48 hours is removed.
        $metrics->rollup($start->addDays(3));
        $this->assertSame(0, DB::table('metric_samples')->where('resolution', 'minute')->count());
        $this->assertSame(1, DB::table('metric_samples')->where('resolution', 'day')->count());
        $this->assertSame($site->id, DB::table('website_metric_samples')->where('resolution', 'day')->value('website_id'));
    }

    public function test_first_sample_is_skipped(): void
    {
        $this->agent()->respond('stats.sample', ['first_sample' => true] + $this->sample(1));
        $this->assertFalse(app(Metrics::class)->collect());
        $this->assertSame(0, DB::table('metric_samples')->count());
    }

    public function test_statistics_page_shows_heaviest_websites(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'company' => 'Bakkerij', 'email' => 'jan@example.test']);
        Website::create(['customer_id' => $customer->id, 'domain' => 'busy.nl', 'status' => 'active']);
        $this->agent()->respond('stats.sample', $this->sample(42, [['site_id' => 'busy_nl', 'cpu_percent' => 12.5, 'memory_bytes' => 1024 ** 2, 'io_read_bps' => 0, 'io_write_bps' => 0]]));
        $this->artisan('whp:metrics:collect')->assertSuccessful();
        app(Metrics::class)->collect(CarbonImmutable::now()->subMinutes(5));

        foreach ([...array_keys(StatsRange::PRESETS), 'nonsense'] as $range) {
            $this->get("/admin/statistics?range={$range}")->assertOk();
        }
        $this->get('/admin/statistics')->assertSee('busy.nl')->assertSee('Bakkerij')->assertSee('12.5%');
        $this->get('/admin/statistics?range=custom&from=2026-01-01T00:00&to=garbage')->assertOk();
    }

    private function history(int $hour, array $sites, int $day): array
    {
        return ['hours' => [['start' => $hour, 'sites' => $sites]], 'days' => [['day' => 'x', 'start' => $day, 'sites' => $sites]]];
    }

    public function test_visits_are_stored_per_site_and_for_the_server(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'shop.nl', 'status' => 'active']);
        $now = CarbonImmutable::parse('2026-11-06 15:20:00');
        $hour = $now->startOfHour()->getTimestamp();
        $counters = ['visitors' => 5, 'pageviews' => 9, 'requests' => 80, 'bots' => 4, 'bytes' => 12345];
        $this->agent()->respond('traffic.history', function () use (&$counters, $hour, $now) {
            return $this->history($hour, ['shop.nl' => $counters, '*' => $counters, 'alias.nl' => $counters], $now->startOfDay()->getTimestamp());
        });

        $visits = app(Visits::class);
        $visits->collect($now);
        $this->assertSame($now->subHours(3)->startOfHour()->getTimestamp(), collect($this->agent()->calls)->firstWhere('action', 'traffic.history')['params']['since']);
        $this->assertSame(4, DB::table('visit_stats')->count(), 'site + server, hour + day; unknown names are skipped');

        // Collecting again updates the same rows instead of adding new ones.
        $counters['visitors'] = 7;
        $visits->collect($now);
        $this->assertSame(4, DB::table('visit_stats')->count());
        $this->assertEquals(7, DB::table('visit_stats')->where('website_id', $site->id)->where('resolution', 'hour')->value('visitors'));
        $this->assertEquals(7, DB::table('visit_stats')->whereNull('website_id')->where('resolution', 'day')->value('visitors'));

        $this->artisan('whp:visits:collect')->assertSuccessful();
    }

    private function seedVisits(): array
    {
        $a = Customer::create(['name' => 'A', 'company' => 'Bakkerij', 'email' => 'a@example.test']);
        $b = Customer::create(['name' => 'B', 'email' => 'b@example.test']);
        $s1 = Website::create(['customer_id' => $a->id, 'domain' => 'one.nl', 'status' => 'active']);
        $s2 = Website::create(['customer_id' => $a->id, 'domain' => 'two.nl', 'status' => 'active']);
        $s3 = Website::create(['customer_id' => $b->id, 'domain' => 'three.nl', 'status' => 'active']);
        $start = CarbonImmutable::now()->startOfHour()->subHours(5);
        foreach (range(0, 4) as $i) {
            foreach ([[$s1->id, 10], [$s2->id, 5], [$s3->id, 1], [null, 15]] as [$id, $v]) {
                DB::table('visit_stats')->insert(['website_id' => $id, 'resolution' => 'hour', 'period_start' => $start->addHours($i),
                    'visitors' => $v, 'pageviews' => $v * 2, 'requests' => $v * 10, 'bots' => 1, 'bytes' => $v * 1000]);
            }
        }

        return [$a, $b, $s1, $s2, $s3];
    }

    public function test_breakdown_per_customer_and_website(): void
    {
        $this->actingAsAdmin();
        [$a, $b, $s1] = $this->seedVisits();

        $data = $this->getJson('/admin/statistics/data?chart=breakdown&metric=visitors&by=customer&range=24h')->assertOk()->json();
        $this->assertSame(['Bakkerij', 'B'], array_column($data['series'], 'label'));
        $this->assertSame(count($data['x']), count($data['series'][0]['values']));
        $this->assertEquals(75, array_sum(array_filter($data['series'][0]['values'])), 'customer A = its two sites, 5 hours');

        $data = $this->getJson('/admin/statistics/data?chart=breakdown&metric=bytes&by=website&limit=1&range=24h')->json();
        $this->assertSame('one.nl', $data['series'][0]['label']);
        $this->assertSame('other', $data['series'][1]['slot']);
        $this->assertEquals(30000, array_sum(array_filter($data['series'][1]['values'])));

        $data = $this->getJson("/admin/statistics/data?chart=breakdown&metric=visitors&by=website&customer={$b->id}&range=7d")->json();
        $this->assertSame(['three.nl'], array_column($data['series'], 'label'));

        $this->getJson('/admin/statistics/data?chart=breakdown&metric=nope')->assertStatus(422);
        $this->getJson('/admin/statistics/data?chart=rm')->assertStatus(422);
    }

    public function test_server_graphs_and_rankings(): void
    {
        $this->actingAsAdmin();
        $this->seedVisits();
        $this->agent()->respond('stats.sample', $this->sample(42));
        app(Metrics::class)->collect(CarbonImmutable::now()->subMinutes(10));
        app(Metrics::class)->collect(CarbonImmutable::now()->subMinutes(9));

        foreach (array_keys(StatisticsData::SERVER) as $chart) {
            foreach (['24h', '90d', '2y'] as $range) {
                $data = $this->getJson("/admin/statistics/data?chart={$chart}&range={$range}")->assertOk()->json();
                $this->assertNotEmpty($data['x']);
            }
        }
        $cpu = $this->getJson('/admin/statistics/data?chart=cpu&range=24h')->json();
        $this->assertSame('minute', $cpu['resolution']);
        $this->assertContains(42.0, array_map('floatval', array_filter($cpu['series'][0]['values'])));
        $visitors = $this->getJson('/admin/statistics/data?chart=visitors&range=24h')->json();
        $this->assertEquals(75, array_sum(array_filter($visitors['series'][0]['values'])));
        $this->assertSame(0, $visitors['series'][0]['values'][array_key_last($visitors['series'][0]['values'])] ?? 0);

        $this->get('/admin/statistics?sort=visitors')->assertOk()->assertSeeInOrder(['one.nl', 'two.nl', 'three.nl'])->assertSee('75');
        $this->get('/admin/statistics?range=custom&from='.urlencode(now()->subHours(3)->toIso8601String()))->assertOk()->assertSee('Reset zoom');
    }

    public function test_range_picks_resolution(): void
    {
        $now = CarbonImmutable::now();
        $r = fn (array $q) => StatsRange::fromRequest(Request::create('/', 'GET', $q), $now);
        $this->assertSame(['minute', 'hour'], [$r(['range' => '24h'])->metricsResolution($now), $r(['range' => '24h'])->visitsResolution($now)]);
        $this->assertSame(['hour', 'hour'], [$r(['range' => '30d'])->metricsResolution($now), $r(['range' => '30d'])->visitsResolution($now)]);
        $this->assertSame(['hour', 'day'], [$r(['range' => '90d'])->metricsResolution($now), $r(['range' => '90d'])->visitsResolution($now)]);
        $this->assertSame(['day', 'day'], [$r(['range' => '2y'])->metricsResolution($now), $r(['range' => '2y'])->visitsResolution($now)]);
        // A short period long ago: minutes are gone, hours are there.
        $old = $r(['range' => 'custom', 'from' => $now->subDays(20)->toIso8601String(), 'to' => $now->subDays(20)->addHours(2)->toIso8601String()]);
        $this->assertSame('hour', $old->metricsResolution($now));
        $this->assertLessThanOrEqual(500, $r(['range' => '24h'])->seconds() / $r(['range' => '24h'])->bucket('minute'));
        // to before from is swapped, the future is cut off.
        $swap = $r(['range' => 'custom', 'from' => $now->toIso8601String(), 'to' => $now->subDay()->toIso8601String()]);
        $this->assertTrue($swap->from < $swap->to);
    }
}
