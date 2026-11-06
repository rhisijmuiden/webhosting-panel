<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Rhis\Monitoring\Mail\AlertMail;
use Rhis\Monitoring\Mail\CustomerAlertMail;
use Rhis\Monitoring\Models\Channel;
use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Contact;
use Rhis\Monitoring\Models\Daily;
use Rhis\Monitoring\Models\Incident;
use Rhis\Monitoring\Models\Notice;
use Rhis\Monitoring\Monitor;
use Rhis\Monitoring\Options;
use Rhis\Monitoring\Teams;
use Rhis\Monitoring\Uptime;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    /** Domains the fake agent reports as down, and other overrides. */
    private array $down = [];

    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agent()->respond('monitor.check', fn (array $p) => array_merge([
            'sites' => array_map(fn ($s) => in_array($s['domain'], $this->down, true)
                ? ['domain' => $s['domain'], 'ok' => false, 'code' => 502, 'ms' => 30, 'error' => null, 'ssl' => ['ok' => false, 'days_left' => null, 'error' => 'refused']]
                : ['domain' => $s['domain'], 'ok' => true, 'code' => 200, 'ms' => 120, 'error' => null,
                    'ssl' => ($s['ssl'] ?? false) ? ['ok' => true, 'days_left' => 60, 'expires_at' => time() + 60 * 86400, 'issuer' => "Let's Encrypt", 'matches' => true] : null],
                $p['sites'] ?? []),
            'services' => [
                ['service' => 'nginx', 'label' => 'Nginx', 'installed' => true, 'active' => true, 'state' => 'running'],
                ['service' => 'postfix', 'label' => 'Postfix', 'installed' => true, 'active' => true, 'state' => 'running'],
            ],
            'disks' => [['mount' => '/', 'pct' => 40.0, 'inodes_pct' => 5.0, 'used' => 40, 'total' => 100, 'free' => 60]],
            'memory' => ['pct' => 40.0], 'load' => [0.2, 0.2, 0.2], 'cpus' => 4, 'mail_queue' => 0,
        ], $this->overrides));
    }

    private function site(string $domain = 'klant.nl', string $locale = 'nl'): Website
    {
        $c = Customer::create(['name' => 'Jan Klant', 'email' => 'info@'.$domain, 'locale' => $locale]);

        return Website::create(['customer_id' => $c->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active', 'php_version' => '8.4']);
    }

    private function monitor(): Monitor
    {
        return app(Monitor::class);
    }

    public function test_a_website_counts_as_down_after_two_failed_checks_and_alerts_once(): void
    {
        User::factory()->admin()->create(['email' => 'beheer@rhis.test', 'locale' => 'nl']);
        $site = $this->site();
        $this->monitor()->run();
        $this->assertSame('ok', Check::where('key', "site:{$site->id}")->value('status'));

        $this->down = ['klant.nl'];
        $this->monitor()->run();
        $this->assertSame('ok', Check::where('key', "site:{$site->id}")->value('status'), 'one hiccup is not an outage');
        Mail::assertNothingSent();

        $this->monitor()->run();
        $this->assertSame('critical', Check::where('key', "site:{$site->id}")->value('status'));
        $incident = Incident::open()->firstOrFail();
        $this->assertSame('website', $incident->kind);
        $this->assertNotNull($incident->notified_at);
        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('beheer@rhis.test') && $m->locale === 'nl');
        $this->assertSame(0, Incident::where('kind', 'ssl')->count(), 'no certificate alarm while the site itself is down');

        $this->monitor()->run();
        Mail::assertSent(AlertMail::class, 1);

        $this->down = [];
        $this->monitor()->run();
        $this->assertNotNull($incident->fresh()->resolved_at);
        Mail::assertSent(AlertMail::class, 2);

        $daily = Daily::where('key', "site:{$site->id}")->firstOrFail();
        $this->assertSame(5, (int) $daily->checks);
        $this->assertSame(2, (int) $daily->up);
    }

    public function test_certificate_disk_and_service_thresholds(): void
    {
        $site = $this->site();
        $this->agent()->respond('monitor.check', fn (array $p) => [
            'sites' => [['domain' => 'klant.nl', 'ok' => true, 'code' => 200, 'ms' => 80, 'ssl' => ['ok' => true, 'days_left' => 10, 'expires_at' => time() + 10 * 86400, 'matches' => true]]],
            'services' => [['service' => 'nginx', 'label' => 'Nginx', 'active' => false, 'state' => 'failed']],
            'disks' => [['mount' => '/', 'pct' => 96.5, 'inodes_pct' => 5.0], ['mount' => '/var/www', 'pct' => 87.0, 'inodes_pct' => 92.0]],
            'memory' => ['pct' => 50.0], 'load' => [9.0, 9.0, 9.0], 'cpus' => 2, 'mail_queue' => 500,
        ]);
        $this->monitor()->run();
        $this->monitor()->run();
        $status = Check::pluck('status', 'key');
        $this->assertSame('warning', $status["ssl:{$site->id}"]);
        $this->assertSame('critical', $status['disk:/']);
        $this->assertSame('warning', $status['disk:/var/www']);
        $this->assertSame('warning', $status['inode:/var/www']);
        $this->assertSame('critical', $status['service:nginx']);
        $this->assertSame('critical', $status['component:web']);
        $this->assertSame('warning', $status['load']);
        $this->assertSame('warning', $status['mail_queue']);
        $this->assertSame(7, Incident::open()->count());
    }

    public function test_teams_channel_gets_an_adaptive_card_and_url_is_encrypted(): void
    {
        Http::fake(['*' => Http::response('', 202)]);
        Channel::query()->delete();
        $ch = Channel::create(['type' => 'teams', 'name' => 'Hosting', 'target' => 'https://prod-01.westeurope.logic.azure.com/workflows/abc', 'min_severity' => 'critical', 'locale' => 'nl']);
        $this->assertStringNotContainsString('logic.azure.com', (string) \DB::table('monitor_channels')->value('target'));

        $this->site();
        $this->down = ['klant.nl'];
        $this->monitor()->run();
        $this->monitor()->run();
        Http::assertSent(function ($req) {
            $card = $req['attachments'][0]['content'] ?? [];

            return str_contains($req->url(), 'logic.azure.com') && ($card['type'] ?? null) === 'AdaptiveCard'
                && str_contains(json_encode($card), 'reageert niet');
        });
        $this->assertNotNull($ch->fresh()->last_sent_at);
        $this->assertFalse(Teams::validUrl('http://example.com/hook'));
        $this->assertFalse(Teams::validUrl('https://127.0.0.1/hook'));
        $this->assertFalse(Teams::validUrl('https://10.0.0.5/hook'));
    }

    public function test_alerts_wait_during_server_maintenance(): void
    {
        User::factory()->admin()->create();
        $this->site();
        \Rhis\ServerAdmin\Models\ServerTaskRun::create(['task' => 'maintenance', 'trigger' => 'window', 'status' => 'running', 'started_at' => now()]);
        $this->down = ['klant.nl'];
        $this->monitor()->run();
        $this->monitor()->run();
        $this->assertSame(1, Incident::open()->count());
        Mail::assertNothingSent();

        \Rhis\ServerAdmin\Models\ServerTaskRun::query()->update(['status' => 'succeeded', 'finished_at' => now()]);
        $this->monitor()->run();
        Mail::assertSent(AlertMail::class);
    }

    public function test_customer_gets_mail_in_own_language_when_opted_in(): void
    {
        $site = $this->site('winkel.nl', 'nl');
        Contact::create(['customer_id' => $site->customer_id, 'enabled' => true, 'extra_emails' => ['bouwer@example.test']]);
        $this->down = ['winkel.nl'];
        $this->monitor()->run();
        $this->monitor()->run();
        Mail::assertSent(CustomerAlertMail::class, fn ($m) => $m->hasTo('info@winkel.nl') && $m->locale === 'nl');
        Mail::assertSent(CustomerAlertMail::class, fn ($m) => $m->hasTo('bouwer@example.test'));
        $this->down = [];
        $this->monitor()->run();
        Mail::assertSent(CustomerAlertMail::class, 4);
    }

    public function test_agent_down_becomes_a_problem(): void
    {
        $this->agent()->respond('monitor.check', new AgentException('unreachable', 'connection refused'));
        $this->monitor()->run();
        $this->monitor()->run();
        $this->assertSame('critical', Check::where('key', 'agent')->value('status'));
        $this->assertTrue(Incident::open()->where('kind', 'agent')->exists());
    }

    public function test_removed_website_is_forgotten(): void
    {
        $site = $this->site();
        $this->monitor()->run();
        $this->assertTrue(Check::where('key', "site:{$site->id}")->exists());
        $site->update(['status' => 'suspended']);
        $this->monitor()->run();
        $this->assertFalse(Check::where('key', "site:{$site->id}")->exists());
    }

    public function test_due_respects_interval(): void
    {
        $o = app(Options::class);
        $this->assertTrue($this->monitor()->due());
        $this->monitor()->run();
        $this->assertFalse($this->monitor()->due());
        $this->travel(5)->minutes();
        $this->assertTrue($this->monitor()->due());
        $o->set('enabled', false);
        $this->assertFalse($this->monitor()->due());
    }

    public function test_admin_pages_render_in_english_and_dutch(): void
    {
        $site = $this->site();
        $this->monitor()->run();
        $admin = $this->actingAsAdmin();
        foreach (['/admin/monitoring', '/admin/monitoring/incidents', '/admin/monitoring/alerts', '/admin/monitoring/settings', '/admin/monitoring/status-page', "/admin/monitoring/websites/{$site->id}"] as $url) {
            $this->get($url)->assertOk()->assertSee('Monitoring');
        }
        $this->get('/admin/monitoring')->assertSee('klant.nl')->assertSee('Websites online');
        $admin->update(['locale' => 'nl']);
        $this->get('/admin/monitoring')->assertOk()->assertSee('Nu controleren');
        $this->get('/admin/monitoring/alerts')->assertSee('Microsoft Teams toevoegen');
    }

    public function test_admin_manages_channels_settings_and_notices(): void
    {
        $this->actingAsAdmin();
        $this->post('/admin/monitoring/alerts', ['type' => 'teams', 'name' => 'T', 'target' => 'http://insecure', 'min_severity' => 'warning', 'locale' => 'en'])->assertSessionHasErrors('target');
        $this->post('/admin/monitoring/alerts', ['type' => 'mail', 'name' => 'Oncall', 'target' => 'a@b.test, c@d.test', 'min_severity' => 'critical', 'locale' => 'nl', 'recoveries' => '0'])->assertRedirect();
        $ch = Channel::where('name', 'Oncall')->firstOrFail();
        $this->assertSame(['a@b.test', 'c@d.test'], $ch->addresses());
        $this->assertFalse($ch->recoveries);
        $this->post("/admin/monitoring/alerts/{$ch->id}/test")->assertSessionHas('status');
        Mail::assertSent(AlertMail::class, 2);

        $this->put('/admin/monitoring/settings', array_merge(Options::defaults(), ['interval' => 2, 'enabled' => '1', 'status_page' => '0', 'heartbeat_url' => '', 'load_warning' => '1.5']))->assertSessionHasNoErrors();
        $this->assertSame(2, app(Options::class)->get('interval'));
        $this->assertFalse(app(Options::class)->get('status_page'));
        $this->get('/status')->assertNotFound();

        $this->post('/admin/monitoring/status-page/notices', ['title' => 'Mailserver onderhoud', 'level' => 'maintenance', 'components' => ['mail']])->assertRedirect();
        $this->assertSame(['mail'], Notice::firstOrFail()->components);
    }

    public function test_public_status_page_shows_platform_but_no_customers(): void
    {
        $this->site('geheim-klant.nl');
        $this->monitor()->run();
        Notice::create(['title' => 'Planned maintenance tonight', 'level' => 'maintenance', 'components' => ['mail']]);
        $this->get('/status')->assertOk()->assertSee('All systems are working')->assertSee('Email')->assertSee('Planned maintenance tonight')
            ->assertDontSee('geheim-klant.nl');
    }

    public function test_client_sees_own_websites_and_saves_alerts(): void
    {
        $mine = $this->site('mijnsite.nl');
        $this->site('ander.nl');
        $this->monitor()->run();
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $mine->customer_id, 'locale' => 'nl']);
        $this->actingAs($user)->get('/uptime')->assertOk()->assertSee('mijnsite.nl')->assertDontSee('ander.nl')->assertSee('Ons platform');
        $this->put('/uptime/alerts', ['enabled' => '1', 'extra_emails' => 'bouwer@example.test'])->assertRedirect();
        $this->assertTrue(Contact::where('customer_id', $mine->customer_id)->value('enabled'));
        $this->put('/uptime/alerts', ['enabled' => '1', 'extra_emails' => 'geen-mail'])->assertSessionHasErrors('extra_emails');
        $this->actingAs($user)->get('/admin/monitoring')->assertForbidden();
    }

    public function test_uptime_report_for_monthly_reports(): void
    {
        $site = $this->site();
        Daily::create(['key' => "site:{$site->id}", 'website_id' => $site->id, 'day' => '2026-10-10', 'checks' => 1000, 'up' => 999, 'down_seconds' => 300, 'ms_total' => 200000, 'ms_count' => 999]);
        Incident::create(['key' => "site:{$site->id}", 'kind' => 'website', 'website_id' => $site->id, 'customer_id' => $site->customer_id, 'title' => 'klant.nl', 'severity' => 'critical',
            'started_at' => '2026-10-10 10:00:00', 'resolved_at' => '2026-10-10 10:09:00']);
        $r = Uptime::report($site->customer, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(99.9, $r['sites']['klant.nl']['uptime']);
        $this->assertSame(9, $r['sites']['klant.nl']['downtime_minutes']);
        $this->assertSame(1, $r['sites']['klant.nl']['incidents']);
        $this->assertSame(200, $r['sites']['klant.nl']['response_ms']);
        $this->assertNull(Uptime::report($site->customer, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-01')));
        if (class_exists(\Rhis\Reports\Reports::class)) {
            $this->assertTrue(\Rhis\Reports\Reports::has('uptime'));
        }
    }

    public function test_server_management_health_check_leaves_alerts_to_monitoring(): void
    {
        $this->assertTrue(\Rhis\ServerAdmin\HealthCheck::monitoringAlerts());
        app(Options::class)->set('enabled', false);
        $this->assertFalse(\Rhis\ServerAdmin\HealthCheck::monitoringAlerts());
    }
}
