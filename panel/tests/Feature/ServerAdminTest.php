<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Rhis\ServerAdmin\Mail\PhpVersionNotice;
use Rhis\ServerAdmin\Mail\ServerNotice;
use Rhis\ServerAdmin\Maintenance;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Rhis\ServerAdmin\Options;
use Rhis\ServerAdmin\RunLocalTask;
use Tests\TestCase;

class ServerAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['server-admin.timezone' => 'Europe/Amsterdam']);
    }

    private function site(string $domain = 'klant.nl', string $php = '8.4'): Website
    {
        $c = Customer::create(['name' => 'Jan Klant', 'email' => 'info@'.$domain, 'locale' => 'nl']);

        return Website::create(['customer_id' => $c->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active', 'php_version' => $php]);
    }

    private function calls(string $action): array
    {
        return array_values(array_filter($this->agent()->calls, fn ($c) => $c['action'] === $action));
    }

    /** job.status answers per job id. */
    private function jobs(array $results): void
    {
        $this->agent()->respond('job.status', fn ($p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => ['done'], 'result' => $results[$p['job']] ?? [], 'error' => null]);
    }

    public function test_pages_render_in_english_and_dutch(): void
    {
        $admin = $this->actingAsAdmin();
        foreach (['/admin/server', '/admin/server/maintenance', '/admin/server/tasks', '/admin/server/php'] as $url) {
            $this->get($url)->assertOk()->assertSee('Server management');
        }
        $this->get('/admin/server')->assertSee('libssl3t64')->assertSee('A reboot finishes the installed updates')
            ->assertSee('Switching the server off is not possible');
        $admin->update(['locale' => 'nl']);
        $this->get('/admin/server')->assertOk()->assertSee('Serverbeheer')->assertSee('Klaarstaande updates');
        $this->get('/admin/server/tasks')->assertSee('Databases optimaliseren');
        $this->get('/admin/tools')->assertOk()->assertSee('Onderhoudsvenster');
        $this->assertSame(6, ServerTask::count());
    }

    public function test_customers_cannot_open_it_and_there_is_no_shutdown(): void
    {
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $c->id]);
        $this->actingAs($user)->get('/admin/server')->assertForbidden();
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/shutdown|poweroff|halt/i', $route->uri());
        }
    }

    public function test_install_updates_and_follow_the_run(): void
    {
        $this->actingAsAdmin();
        $this->post('/admin/server/updates/install', ['mode' => 'security'])->assertRedirect();
        $run = ServerTaskRun::firstOrFail();
        $this->assertSame('upgrade', $run->task);
        $this->assertSame(['mode' => 'security'], $this->calls('server.upgrade')[0]['params']);
        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => ['Run id '.str_repeat('a', 32), 'Setting up openssl'], 'result' => ['reboot_reasons' => []], 'error' => null]);
        $this->getJson("/admin/server/runs/{$run->id}/status")->assertOk()->assertJson(['status' => 'succeeded', 'finished' => true]);
        $this->assertSame(str_repeat('a', 32), $run->fresh()->apt_run);
        $this->get("/admin/server/runs/{$run->id}")->assertOk()->assertSee('Setting up openssl');
        $this->post('/admin/server/updates/install', ['mode' => 'dist-upgrade'])->assertSessionHasErrors('mode');
    }

    public function test_reboot_needs_the_hostname_and_checks_websites_after(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->site();
        $this->post('/admin/server/reboot', ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->assertSame(0, ServerTaskRun::count());

        $this->post('/admin/server/reboot', ['confirm' => gethostname()])->assertRedirect();
        $run = ServerTaskRun::firstOrFail();
        $this->assertSame('manual', $run->trigger);

        $this->artisan('whp:server:tick')->assertSuccessful();
        $this->assertSame(['delay' => 10, 'reason' => 'manual'], $this->calls('server.reboot')[0]['params']);
        $this->assertSame('rebooting', $run->fresh()->result['phase']);
        $this->assertSame([['domain' => 'klant.nl', 'ssl' => true]], $this->calls('server.health')[0]['params']['sites']);

        // Still the old boot: wait.
        $this->artisan('whp:server:tick');
        $this->assertTrue($run->fresh()->isRunning());

        // Back since five minutes.
        $this->agent()->respond('server.status', ['boot_time' => now()->getTimestamp() + 1, 'kernel' => '6.12.48', 'reboot_reasons' => []]);
        $this->travel(5)->minutes();
        $this->artisan('whp:server:tick');
        $run->refresh();
        $this->assertSame('succeeded', $run->status, (string) $run->log.json_encode($run->result));
        $this->assertTrue($run->result['rebooted']);
        $this->assertCount(2, $this->calls('server.health'));
        Mail::assertNothingSent(); // manual reboot without problems: no mail
    }

    public function test_maintenance_window_updates_reboots_and_reports_a_broken_site(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->site();
        $o = app(Options::class);
        $o->set('window_start', '03:00');
        $o->set('window_end', '04:00');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10 03:05', 'Europe/Amsterdam'));
        $this->agent()->respond('job.status', ['status' => 'running', 'log' => ['Unpacking'], 'result' => null, 'error' => null]);

        $this->artisan('whp:server:tick');
        $session = ServerTaskRun::where('task', 'maintenance')->firstOrFail();
        $this->assertSame('window', $session->trigger);
        $this->assertSame('upgrading', $session->result['phase']);
        $this->assertSame('security', $this->calls('server.upgrade')[0]['params']['mode']);
        $this->assertCount(1, $this->calls('server.health'));

        // Update done; the fake status says a new kernel waits.
        $this->jobs([]);
        $this->artisan('whp:server:tick');
        $session->refresh();
        $this->assertSame('rebooting', $session->result['phase']);
        $this->assertSame(60, $this->calls('server.reboot')[0]['params']['delay']);
        Mail::assertSent(ServerNotice::class, fn ($m) => str_contains($m->heading, 'reboots for updates'));

        // Back, but the website now answers 502 and stays like that.
        $this->agent()->respond('server.status', ['boot_time' => now()->getTimestamp() + 1, 'kernel' => '6.12.48', 'reboot_reasons' => []]);
        $this->agent()->respond('server.health', fn ($p) => ['services' => [], 'services_down' => ['php8.4-fpm'],
            'sites' => [['domain' => 'klant.nl', 'code' => 502, 'ok' => false, 'ms' => 5, 'error' => null]], 'sites_down' => ['klant.nl'], 'checked_at' => time()]);
        $this->travel(5)->minutes();
        $this->artisan('whp:server:tick');
        $this->assertSame('postcheck_retry', $session->fresh()->result['phase'], $session->fresh()->log);
        $this->assertSame(['service' => 'php8.4-fpm'], $this->calls('service.restart')[0]['params']);
        $this->travel(2)->minutes();
        $this->artisan('whp:server:tick');
        $session->refresh();
        $this->assertSame('failed', $session->status);
        $this->assertSame(['klant.nl'], $session->result['regressions']['sites']);
        Mail::assertSent(ServerNotice::class, fn ($m) => $m->level === 'problem' && in_array('Website klant.nl does not answer since the maintenance', $m->lines, true));

        // Same window: no second session.
        $this->artisan('whp:server:tick');
        $this->assertSame(1, ServerTaskRun::where('task', 'maintenance')->count());
    }

    public function test_no_session_outside_the_window_or_on_a_skipped_day(): void
    {
        $o = app(Options::class);
        $o->set('window_days', [1, 2, 3, 4, 5]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08 03:10', 'Europe/Amsterdam')); // Sunday
        app(Maintenance::class)->tick();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 12:00', 'Europe/Amsterdam'));
        app(Maintenance::class)->tick();
        $this->assertSame(0, ServerTaskRun::count());
        // A window past midnight.
        $o->set('window_start', '23:30');
        $o->set('window_end', '00:30');
        $this->assertNotNull($o->windowAt(\Illuminate\Support\Carbon::parse('2026-11-10 00:10', 'Europe/Amsterdam')));
    }

    public function test_critical_cve_is_patched_with_mails_and_only_once(): void
    {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'beheer@example.test']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10 14:00', 'Europe/Amsterdam'));
        $scan = ['critical' => true, 'max_score' => 9.8, 'packages' => [['name' => 'openssl', 'candidate' => '3.5.1-1+deb13u1',
            'cves' => [['id' => 'CVE-2026-1111', 'score' => 9.8], ['id' => 'CVE-2026-2222', 'score' => 5.0]]]]];
        $this->agent()->respond('server.security_scan', ['job' => 'scan-1']);

        app(\Rhis\ServerAdmin\Runner::class)->runTask(ServerTask::synced()->get('security_check'));
        $this->agent()->respond('job.status', fn ($p) => ['status' => $p['job'] === 'scan-1' ? 'succeeded' : 'running', 'log' => [], 'result' => $p['job'] === 'scan-1' ? $scan : null, 'error' => null]);
        $this->artisan('whp:server:tick'); // follows the scan -> critical session, upgrade started
        $session = ServerTaskRun::where('task', 'maintenance')->firstOrFail();
        $this->assertSame('critical', $session->trigger);
        $this->assertSame('CVE-2026-1111', $session->result['cves'][0]['id']);
        $this->assertCount(1, $session->result['cves']);
        Mail::assertSent(ServerNotice::class, fn ($m) => str_contains($m->heading, 'installing now') && str_contains(implode(' ', $m->lines), 'CVE-2026-1111'));

        $this->jobs(['scan-1' => $scan]);
        $this->artisan('whp:server:tick'); // upgrade done -> reboot soon (15 minutes)
        $this->assertSame(900, collect($this->calls('server.reboot'))->last()['params']['delay']);
        Mail::assertSent(ServerNotice::class, fn ($m) => str_contains($m->heading, 'reboots in 15 minutes'));

        // The same CVE in a later scan does not start a second session.
        $session->finish('succeeded');
        app(Maintenance::class)->onRunFinished(ServerTaskRun::create(['task' => 'security_check', 'status' => 'succeeded', 'result' => $scan]));
        $this->assertSame(1, ServerTaskRun::where('task', 'maintenance')->count());
    }

    public function test_php_end_of_life_moves_sites_rolls_back_broken_ones_and_removes_the_old_version(): void
    {
        Mail::fake();
        User::factory()->admin()->create();
        config(['server-admin.php_eol.8.3' => '2026-10-01']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10 09:00', 'Europe/Amsterdam'));
        $good = $this->site('goed.nl', '8.3');
        $bad = $this->site('stuk.nl', '8.3');
        $this->agent()->respond('server.health', fn ($p) => ['services' => [], 'services_down' => [], 'sites_down' => [], 'checked_at' => time(),
            'sites' => array_map(fn ($s) => ['domain' => $s['domain'], 'ok' => ! ($s['domain'] === 'stuk.nl' && Website::where('domain', 'stuk.nl')->value('php_version') === '8.4'),
                'code' => 200, 'ms' => 1, 'error' => null], $p['sites'])]);

        $task = ServerTask::synced()->get('php_eol');
        app(\Rhis\ServerAdmin\Runner::class)->runTask($task);

        $this->assertSame('8.4', $good->fresh()->php_version);
        $this->assertSame('8.3', $bad->fresh()->php_version);
        $phpCalls = array_map(fn ($c) => $c['params'], $this->calls('site.php'));
        $this->assertContains(['domain' => 'stuk.nl', 'php' => '8.3'], $phpCalls); // rolled back
        $this->assertSame(['stuk.nl' => '8.4'], app(Options::class)->state('php_failed'));
        $this->assertCount(0, $this->calls('server.php_remove')); // stuk.nl still on 8.3
        Mail::assertSent(ServerNotice::class, fn ($m) => $m->level === 'problem');

        // The customer fixes the site and moves it; the next run removes PHP 8.3.
        $bad->update(['php_version' => '8.4']);
        app(\Rhis\ServerAdmin\Runner::class)->runTask($task);
        $this->assertSame(['version' => '8.3'], $this->calls('server.php_remove')[0]['params']);
    }

    public function test_php_notice_goes_to_customers_once(): void
    {
        Mail::fake();
        config(['server-admin.php_eol.8.3' => '2026-11-30']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10 09:00', 'Europe/Amsterdam'));
        $this->site('klant.nl', '8.3');
        $task = ServerTask::synced()->get('php_eol');
        app(\Rhis\ServerAdmin\Runner::class)->runTask($task);
        app(\Rhis\ServerAdmin\Runner::class)->runTask($task);
        Mail::assertSent(PhpVersionNotice::class, 1);
        Mail::assertSent(PhpVersionNotice::class, fn ($m) => $m->hasTo('info@klant.nl') && $m->target === '8.4' && $m->locale === 'nl');
        $this->assertCount(0, $this->calls('site.php'));
    }

    public function test_tasks_schedule_and_settings(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10 10:00', 'Europe/Amsterdam')); // Tuesday
        $task = ServerTask::synced()->get('db_optimize');
        $this->assertSame('2026-11-15 04:40', $task->next_run_at->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'));

        $this->put('/admin/server/tasks/db_optimize', ['enabled' => 1, 'frequency' => 'daily', 'time' => '02:30', 'options' => ['min_free_mb' => 100, 'analyze' => 0]])
            ->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame('2026-11-11 02:30', $task->next_run_at->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'));
        $this->assertSame(['min_free_mb' => 100, 'analyze' => false], $task->options);

        $this->post('/admin/server/tasks/db_optimize/run')->assertRedirect();
        $this->assertSame(['min_free_mb' => 100, 'analyze' => false], $this->calls('server.db_optimize')[0]['params']);
        $this->post('/admin/server/tasks/health_check/run')->assertRedirect();
        Queue::assertPushed(RunLocalTask::class);

        $this->put('/admin/server/maintenance', [
            'window_enabled' => 1, 'window_days' => [1, 3], 'window_start' => '02:00', 'window_end' => '03:00', 'window_install' => 'all',
            'window_reboot' => 0, 'mail_mode' => 'always', 'mail_to' => 'ops@example.test', 'critical_enabled' => 1, 'critical_threshold' => 8.5,
            'critical_reboot' => 'window', 'critical_reboot_delay' => 30, 'php_eol_enabled' => 1, 'php_notice_days' => 60, 'php_grace_days' => 14,
            'php_remove_old' => 0, 'php_notify_customers' => 1,
        ])->assertSessionHasNoErrors();
        $o = app(Options::class);
        $this->assertSame([1, 3], $o->get('window_days'));
        $this->assertFalse($o->get('window_reboot'));
        $this->assertSame(8.5, $o->get('critical_threshold'));
        $this->assertSame(['ops@example.test'], $o->recipients());
        $this->assertSame(['mode' => 'window'], $this->calls('server.auto_updates')[0]['params']);
        $this->put('/admin/server/maintenance', ['window_start' => '25:00'])->assertSessionHasErrors(['window_start', 'mail_mode']);
    }
}
