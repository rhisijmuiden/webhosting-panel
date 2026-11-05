<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Website;
use App\Services\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        foreach (array_keys(Metrics::RANGES) as $range) {
            $this->get("/admin/statistics?range={$range}")->assertOk();
        }
        $this->get('/admin/statistics')->assertSee('busy.nl')->assertSee('Bakkerij')->assertSee('12.5%');
    }
}
