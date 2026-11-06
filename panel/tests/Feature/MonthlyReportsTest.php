<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Rhis\Reports\Mail\MonthlyReportMail;
use Rhis\Reports\Models\Preference;
use Rhis\Reports\Models\Send;
use Rhis\Reports\ReportBuilder;
use Rhis\Reports\Reports;
use Tests\TestCase;

class MonthlyReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Reports::forget('uptime');
        parent::tearDown();
    }

    private function site(string $domain = 'klant.nl', array $customer = []): Website
    {
        $c = Customer::create($customer + ['name' => 'Jan Klant', 'email' => 'info@'.$domain, 'locale' => 'nl']);

        return Website::create(['customer_id' => $c->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active', 'php_version' => '8.4']);
    }

    private function seedMonth(Website $site): void
    {
        // October 2026: 3 days with visitors; September: one day, for the comparison.
        foreach ([['2026-10-02', 100], ['2026-10-15', 250], ['2026-10-31', 50], ['2026-09-10', 200]] as [$day, $visitors]) {
            DB::table('visit_stats')->insert(['website_id' => $site->id, 'resolution' => 'day', 'period_start' => $day.' 00:00:00',
                'visitors' => $visitors, 'pageviews' => $visitors * 3, 'requests' => $visitors * 20, 'bots' => 5, 'bytes' => $visitors * 1_000_000]);
        }
        $this->agent()->respond('backup.list', ['snapshots' => [
            ['id' => 'a', 'short_id' => 'a', 'time' => '2026-10-30T02:34:00+00:00', 'kind' => 'incremental', 'scope' => 'site:'.$site->domain, 'host' => 'web01', 'paths' => []],
            ['id' => 'b', 'short_id' => 'b', 'time' => '2026-10-29T02:34:00+00:00', 'kind' => 'full', 'scope' => 'site:'.$site->domain, 'host' => 'web01', 'paths' => []],
            ['id' => 'c', 'short_id' => 'c', 'time' => '2026-11-01T02:34:00+00:00', 'kind' => 'incremental', 'scope' => 'site:'.$site->domain, 'host' => 'web01', 'paths' => []],
            ['id' => 'd', 'short_id' => 'd', 'time' => '2026-10-29T02:34:00+00:00', 'kind' => 'full', 'scope' => 'site:other.nl', 'host' => 'web01', 'paths' => []],
        ]]);
        DB::table('security_findings')->insert([
            ['website_id' => $site->id, 'kind' => 'vulnerability', 'fingerprint' => 'f1', 'severity' => 'high', 'title' => 'XSS', 'status' => 'resolved',
                'first_seen_at' => '2026-10-03 04:00:00', 'resolved_at' => '2026-10-05 04:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (['incoming' => 'sent', 'outgoing' => 'sent'] as $direction => $status) {
            DB::table('mail_log_messages')->insert(['customer_id' => $site->customer_id, 'logged_at' => '2026-10-10 10:00:00', 'direction' => $direction, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('mail_log_messages')->insert(['customer_id' => $site->customer_id, 'logged_at' => '2026-10-11 10:00:00', 'direction' => 'incoming', 'status' => 'rejected', 'created_at' => now(), 'updated_at' => now()]);
        Reports::provide('uptime', fn (Customer $c, CarbonImmutable $from, CarbonImmutable $to) => $from->format('Y-m') === '2026-10'
            ? ['sites' => [$site->domain => ['uptime' => 99.97, 'downtime_minutes' => 13, 'incidents' => 1, 'response_ms' => 210], 'not-mine.nl' => ['uptime' => 1]]]
            : null);
    }

    public function test_builder_collects_the_month(): void
    {
        $site = $this->site();
        $this->seedMonth($site);

        $r = app(ReportBuilder::class)->build($site->customer, ReportBuilder::month('2026-10'));

        $this->assertSame(400, $r['visitors']['total']);
        $this->assertSame(1200, $r['visitors']['pageviews']);
        $this->assertSame(200, $r['visitors']['previous']);
        $this->assertSame(100.0, (float) $r['visitors']['change']);
        $this->assertCount(31, $r['visitors']['daily']);
        $this->assertSame(15, $r['visitors']['best_day']->day);
        $this->assertSame(99.97, $r['uptime']['average']);
        $this->assertSame(13, $r['uptime']['downtime_minutes']);
        $this->assertSame(2, $r['backups']['server']);
        $this->assertSame(30, $r['backups']['last']->day);
        $this->assertSame(1, $r['security']['resolved']);
        $this->assertSame(0, $r['security']['open']);
        $this->assertSame(['received' => 1, 'sent' => 1, 'spam' => 1], $r['mail']);
        $this->assertArrayNotHasKey('not-mine.nl', $r['sites']);
    }

    public function test_report_mail_renders_in_english_and_dutch(): void
    {
        $site = $this->site();
        $this->seedMonth($site);
        $report = app(ReportBuilder::class)->build($site->customer, ReportBuilder::month('2026-10'));

        $en = (new MonthlyReportMail($site->customer, $report))->locale('en');
        $this->assertSame('Your hosting in October 2026', $en->envelope()->subject);
        $html = $en->render();
        $this->assertStringContainsString('Visitors per day', $html);
        $this->assertStringContainsString('99.97%', $html);
        $this->assertStringContainsString('klant.nl', $html);

        $nl = (new MonthlyReportMail($site->customer, $report))->locale('nl');
        $html = $nl->render();
        $this->assertStringContainsString('Je hosting in oktober 2026', $html);
        $this->assertStringContainsString('Bezoekers per dag', $html);
        $this->assertStringContainsString('99,97%', $html);
        $this->assertStringContainsString('Drukste dag: 15 oktober', $html);
    }

    public function test_scheduled_run_sends_once_per_month_and_respects_opt_out(): void
    {
        Mail::fake();
        $site = $this->site();
        $off = $this->site('stop.nl', ['name' => 'Stop', 'email' => 'stop@stop.nl']);
        Preference::for($off->customer)->fill(['enabled' => false])->save();
        Customer::create(['name' => 'No site', 'email' => 'leeg@example.nl']);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:30', config('app.timezone')));
        $this->artisan('whp:reports:send')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:07', config('app.timezone')));
        $this->artisan('whp:reports:send')->assertSuccessful();
        Mail::assertSent(MonthlyReportMail::class, 1);
        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->hasTo('info@klant.nl') && $m->locale === 'nl' && $m->report['period'] === '2026-10');
        $this->assertSame('sent', Send::where('customer_id', $site->customer_id)->value('status'));

        // Next hour: nothing new.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:07', config('app.timezone')));
        $this->artisan('whp:reports:send')->assertSuccessful();
        Mail::assertSent(MonthlyReportMail::class, 1);
    }

    public function test_admin_page_preview_and_test_copy(): void
    {
        Mail::fake();
        $site = $this->site();
        $admin = $this->actingAsAdmin();

        $this->get(route('admin.reports.index'))->assertOk()->assertSee('Monthly reports')->assertSee('Jan Klant');
        $this->get(route('admin.reports.preview', [$site->customer, 'locale' => 'en']))->assertOk()->assertSee('Security and maintenance');
        $this->get(route('admin.reports.preview', [$site->customer, 'locale' => 'nl']))->assertOk()->assertSee('Beveiliging en onderhoud');

        $this->post(route('admin.reports.send', $site->customer), ['to_me' => 1])->assertRedirect();
        Mail::assertSent(MonthlyReportMail::class, fn ($m) => $m->hasTo(strtolower($admin->email)) && ! $m->hasTo('info@klant.nl'));
        $this->assertSame('test', Send::value('kind'));

        // A test copy does not stop the scheduled report.
        $this->artisan('whp:reports:send', ['--force' => true])->assertSuccessful();
        Mail::assertSent(MonthlyReportMail::class, fn ($m) => $m->hasTo('info@klant.nl'));

        $this->put(route('admin.reports.delivery', $site->customer), ['enabled' => 0])->assertRedirect();
        $this->assertFalse(Preference::for($site->customer)->enabled);
        $this->put(route('admin.reports.settings.save'), ['enabled' => 1, 'default_on' => 1, 'day' => 3, 'time' => '07:30', 'bcc' => '', 'support_email' => 'support@rhis.nl'])->assertRedirect();
        $this->assertSame(3, app(\Rhis\Reports\Options::class)->day());
    }

    public function test_client_sees_report_and_can_opt_out(): void
    {
        $site = $this->site();
        $user = User::factory()->create(['role' => 'client', 'customer_id' => $site->customer_id]);
        $this->actingAs($user);

        $this->get(route('client.reports.index'))->assertOk()->assertSee('klant.nl')->assertSee('Receive by mail');
        $this->get(route('client.reports.view'))->assertOk()->assertSee('Open the control panel');

        $this->put(route('client.reports.delivery'), ['enabled' => 0, 'extra_emails' => 'baas@klant.nl'])->assertRedirect(route('client.reports.index'));
        $p = Preference::for($site->customer);
        $this->assertFalse($p->enabled);
        $this->assertSame(['baas@klant.nl'], $p->extra_emails);
        $this->assertSame([], $p->recipients());

        $this->put(route('client.reports.delivery'), ['enabled' => 1, 'extra_emails' => 'not-an-address'])->assertSessionHasErrors('extra_emails');
    }
}
