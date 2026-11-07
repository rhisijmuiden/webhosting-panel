<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Rhis\Cron\CronManager;
use Rhis\Cron\Mail\CronJobFailed;
use Rhis\Cron\Models\CronJob;
use Rhis\Cron\Models\CronRun;
use Rhis\Cron\Schedule;
use Tests\TestCase;

class CronJobsTest extends TestCase
{
    use RefreshDatabase;

    private function calls(string $action): Collection
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    private function site(string $domain = 'klant.nl', ?Customer $customer = null): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain, 'locale' => 'nl']);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active']);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Laravel scheduler', 'type' => 'command', 'command' => 'cd app && php artisan schedule:run',
            'schedule' => '* * * * *', 'timeout' => 15, 'notify_failure' => '1', 'notify_email' => '',
        ];
    }

    private function client(Customer $customer): User
    {
        $user = User::factory()->create(['customer_id' => $customer->id, 'role' => 'client']);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    private function job(Website $site, array $over = []): CronJob
    {
        return CronJob::create($over + ['website_id' => $site->id, 'uid' => CronJob::newUid(), 'name' => 'Import',
            'type' => 'command', 'command' => 'php import.php', 'schedule' => '0 3 * * *', 'timeout' => 60]);
    }

    /** Let the fake agent report these runs. */
    private function runs(array $runs): void
    {
        $this->agent()->respond('cron.runs', fn ($p) => ['runs' => array_values(array_filter($runs,
            fn ($r) => $r['finished'] > ($p['since'] ?? 0) && (! isset($p['ids']) || in_array($r['id'], $p['ids'], true))))]);
    }

    public function test_schedule_validation_description_and_next_runs(): void
    {
        $this->assertNull(Schedule::error('*/5 * * * *'));
        $this->assertNull(Schedule::error('@daily'));
        $this->assertNull(Schedule::error('0 9 * * mon-fri'));
        $this->assertNull(Schedule::error('0 8 * * 7'));
        $this->assertNotNull(Schedule::error('* * * *'));
        $this->assertNotNull(Schedule::error('60 * * * *'));
        $this->assertNotNull(Schedule::error('*/0 * * * *'));
        $this->assertNotNull(Schedule::error('5-1 * * * *'));
        $this->assertSame('hour', Schedule::errorField('0 24 * * *'));
        $this->assertSame('0 0 * * *', Schedule::normalize(' @daily '));
        $this->assertSame('Every 5 minutes', Schedule::describe('*/5 * * * *'));
        $this->assertSame('Every day at 03:30', Schedule::describe('30 3 * * *'));
        $this->assertSame('Every Monday at 03:00', Schedule::describe('0 3 * * 1'));
        $this->assertSame('Monday to Friday at 09:00', Schedule::describe('0 9 * * 1-5'));
        $this->assertNull(Schedule::describe('0 3 1,15 * 5'));
        $this->assertCount(3, Schedule::nextRuns('0 3 * * *'));
        $this->assertSame(5, Schedule::shortestGap('*/5 * * * *'));
        app()->setLocale('nl');
        $this->assertSame('Elke dag om 03:30', Schedule::describe('30 3 * * *'));
        $this->assertSame('Elke maandag om 03:00', Schedule::describe('0 3 * * 1'));
    }

    public function test_client_creates_runs_and_sees_output(): void
    {
        $site = $this->site();
        $this->client($site->customer);
        $this->get('/cron')->assertOk()->assertSee('Cron jobs')->assertSee('No cron jobs yet.');
        $this->get('/cron/create')->assertOk()->assertSee('What should run?')->assertSee('Every 5 minutes');

        $this->post('/cron', $this->form(['website_id' => $site->id]))->assertSessionHasNoErrors()->assertRedirect();
        $job = CronJob::firstOrFail();
        $p = $this->calls('cron.apply')->last()['params'];
        $this->assertSame($job->uid, $p['id']);
        $this->assertSame('klant.nl', $p['domain']);
        $this->assertSame('command', $p['type']);
        $this->assertSame('cd app && php artisan schedule:run', $p['command']);
        $this->assertSame('* * * * *', $p['schedule']);
        $this->assertSame(15, $p['timeout']);
        $this->assertTrue($p['enabled']);
        $this->assertSame(config('app.timezone'), $p['timezone']);

        $this->get('/cron')->assertOk()->assertSee('Laravel scheduler')->assertSee('Every minute');
        $this->post("/cron/{$job->id}/run")->assertRedirect()->assertSessionHas('cron_started');
        $this->assertSame($job->uid, $this->calls('cron.run')->last()['params']['id']);

        // The fake agent finished the run right away; the job page fetches it.
        $this->get("/cron/{$job->id}")->assertOk()->assertSee('Recent runs')->assertSee('Succeeded')->assertSee('Done: 3 batches pruned.');
        $this->assertSame('success', $job->refresh()->last_status);
        $this->assertSame(1, $job->runs()->count());
        $run = $job->runs()->first();
        $this->get("/cron/{$job->id}/runs/{$run->id}")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertSee('batches pruned');
        $this->getJson("/cron/{$job->id}/poll")->assertOk()->assertJsonPath('running', false)->assertJsonPath('last_run', $run->id);
    }

    public function test_validation_limits_and_ownership(): void
    {
        $site = $this->site();
        $other = $this->site('ander.nl');
        $foreign = $this->job($other);
        $this->withPackage($site, HostingPackage::create(['name' => 'Klein', 'max_cronjobs' => 1]));
        $this->client($site->customer);

        $this->post('/cron', $this->form(['website_id' => $site->id, 'schedule' => '61 * * * *']))->assertSessionHasErrors('schedule');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'schedule' => '* * *']))->assertSessionHasErrors('schedule');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'type' => 'php', 'command' => '../../etc/passwd']))->assertSessionHasErrors('command');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'type' => 'url', 'command' => 'ftp://x']))->assertSessionHasErrors('command');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'timeout' => 7]))->assertSessionHasErrors('timeout');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'command' => "a\nb"]))->assertSessionHasErrors('command');
        $this->post('/cron', $this->form(['website_id' => $other->id]))->assertSessionHasErrors('website_id');
        $this->assertSame(0, CronJob::where('website_id', $site->id)->count());

        $this->post('/cron', $this->form(['website_id' => $site->id, 'type' => 'php', 'command' => '/public/cron.php', 'args' => '--quiet']))->assertSessionHasNoErrors();
        $job = CronJob::where('website_id', $site->id)->firstOrFail();
        $this->assertSame('public/cron.php', $job->command);
        $this->assertSame('--quiet', $this->calls('cron.apply')->last()['params']['args']);
        $this->post('/cron', $this->form(['website_id' => $site->id]))->assertSessionHasErrors('name');

        // Someone else's job does not exist for this customer.
        $this->get("/cron/{$foreign->id}")->assertNotFound();
        $this->post("/cron/{$foreign->id}/run")->assertNotFound();
        $this->delete("/cron/{$foreign->id}")->assertNotFound();
        $this->get("/cron/{$foreign->id}/runs/1")->assertNotFound();
    }

    public function test_package_without_cron_jobs_hides_the_feature(): void
    {
        $site = $this->site();
        $this->withPackage($site, HostingPackage::create(['name' => 'Geen', 'max_cronjobs' => 0]));
        $this->client($site->customer);
        $this->get('/cron')->assertOk()->assertDontSee('New cron job');
        $this->get('/cron/create')->assertNotFound();
        $this->assertFalse(CronManager::allowed($site->refresh()));
    }

    public function test_minimum_interval_for_customers(): void
    {
        config(['cron.min_interval' => 5]);
        $site = $this->site();
        $this->client($site->customer);
        $this->post('/cron', $this->form(['website_id' => $site->id, 'schedule' => '* * * * *']))->assertSessionHasErrors('schedule');
        $this->post('/cron', $this->form(['website_id' => $site->id, 'schedule' => '*/5 * * * *']))->assertSessionHasNoErrors();
        $this->postJson('/cron/preview', ['schedule' => '* * * * *'])->assertOk()->assertJsonPath('label', null);
        $this->postJson('/cron/preview', ['schedule' => '0 3 * * *'])->assertOk()->assertJsonPath('label', 'Every day at 03:00')->assertJsonPath('error', null);
    }

    public function test_update_toggle_stop_and_remove(): void
    {
        $site = $this->site();
        $job = $this->job($site);
        $this->client($site->customer);

        $this->put("/cron/{$job->id}", $this->form(['schedule' => '@hourly', 'name' => 'Feeds', 'notify_failure' => '0']))->assertSessionHasNoErrors();
        $job->refresh();
        $this->assertSame('0 * * * *', $job->schedule);
        $this->assertFalse($job->notify_failure);
        $this->assertSame('0 * * * *', $this->calls('cron.apply')->last()['params']['schedule']);

        $this->post("/cron/{$job->id}/toggle")->assertRedirect();
        $this->assertFalse($job->refresh()->enabled);
        $this->assertFalse($this->calls('cron.apply')->last()['params']['enabled']);
        $this->post("/cron/{$job->id}/toggle");
        $this->assertTrue($job->refresh()->enabled);

        $this->post("/cron/{$job->id}/stop")->assertRedirect();
        $this->assertSame($job->uid, $this->calls('cron.stop')->last()['params']['id']);

        $this->delete("/cron/{$job->id}")->assertRedirect('/cron');
        $this->assertSame($job->uid, $this->calls('cron.remove')->last()['params']['id']);
        $this->assertNull(CronJob::find($job->id));
    }

    public function test_agent_error_rolls_back(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->agent()->respond('cron.apply', new AgentException('command_failed', 'systemctl failed'));
        $this->post('/admin/cron', $this->form(['website_id' => $site->id]))->assertSessionHasErrors('agent');
        $this->assertSame(0, CronJob::count());

        $job = $this->job($site);
        $this->put("/admin/cron/{$job->id}", $this->form(['schedule' => '*/10 * * * *']))->assertSessionHasErrors('agent');
        $job->refresh();
        $this->assertSame('0 3 * * *', $job->schedule);
        $this->assertSame('systemctl failed', $job->last_error);
    }

    public function test_admin_sees_all_jobs_and_filters_by_website(): void
    {
        $a = $this->site('a.nl');
        $b = $this->site('b.nl');
        $this->job($a, ['name' => 'Job A']);
        $this->job($b, ['name' => 'Job B']);
        $this->actingAsAdmin();
        $this->get('/admin/cron')->assertOk()->assertSee('Job A')->assertSee('Job B');
        $this->get('/admin/cron?website='.$a->id)->assertOk()->assertSee('Job A')->assertDontSee('Job B');
        $this->get('/admin/cron/create?website='.$b->id)->assertOk();
        // Admins may schedule every minute even with a customer minimum.
        config(['cron.min_interval' => 10]);
        $this->post('/admin/cron', $this->form(['website_id' => $a->id]))->assertSessionHasNoErrors();
    }

    public function test_sync_keeps_runs_and_mails_on_failure_once(): void
    {
        Mail::fake();
        $site = $this->site();
        $job = $this->job($site);
        $t = now()->timestamp;
        $run = fn (string $inv, string $status, int $at, array $extra = []) => $extra + ['id' => $job->uid, 'invocation' => $inv,
            'started' => $at - 2, 'finished' => $at + 0.5, 'duration_ms' => 2000, 'status' => $status,
            'exit_status' => $status === 'success' ? 0 : 1, 'output' => "line 1\nPHP Fatal error: boom\n", 'truncated' => false];

        $this->runs([$run('i1', 'failed', $t - 300)]);
        $this->artisan('whp:cron:sync')->assertSuccessful();
        Mail::assertSent(CronJobFailed::class, fn ($m) => $m->hasTo('info@klant.nl') && $m->locale === 'nl');
        $job->refresh();
        $this->assertSame('failed', $job->last_status);
        $this->assertSame(1, $job->failures);
        $this->assertNotNull($job->notified_at);

        // Same run again (overlap) and a second failure: kept once, no second mail within a day.
        $this->runs([$run('i1', 'failed', $t - 300), $run('i2', 'timeout', $t - 200, ['exit_status' => null, 'signal' => 'TERM'])]);
        $this->artisan('whp:cron:sync')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame(2, CronRun::count());
        $this->assertSame(2, $job->refresh()->failures);
        $this->assertSame('timeout', $job->last_status);

        // Works again: the counter resets, the next failure mails again.
        $this->runs([$run('i3', 'success', $t - 100), $run('i4', 'failed', $t - 50)]);
        $this->artisan('whp:cron:sync')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertSame(1, $job->refresh()->failures);

        // Mails can be switched off, and runs of unknown jobs are ignored.
        $job->update(['notify_failure' => false, 'notified_at' => null]);
        $this->runs([$run('i5', 'failed', $t - 10), ['id' => 'ffffffffff', 'invocation' => 'x', 'finished' => $t, 'status' => 'failed']]);
        $this->artisan('whp:cron:sync')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertSame(5, CronRun::count());

        $html = (new CronJobFailed($job->refresh(), CronRun::where('invocation', 'i5')->first()))->render();
        $this->assertStringContainsString('PHP Fatal error: boom', $html);
    }

    public function test_history_is_pruned(): void
    {
        config(['cron.keep_runs' => 5]);
        $site = $this->site();
        $job = $this->job($site, ['notify_failure' => false]);
        $t = now()->timestamp;
        $this->runs(collect(range(1, 8))->map(fn ($i) => ['id' => $job->uid, 'invocation' => "r$i", 'started' => $t - 1000 + $i * 10,
            'finished' => $t - 1000 + $i * 10 + 1, 'status' => 'success', 'output' => ''])->all());
        app(CronManager::class)->syncRuns();
        $this->assertSame(5, CronRun::count());
        $this->assertSame(['r8', 'r7', 'r6', 'r5', 'r4'], $job->runs()->pluck('invocation')->all());
    }

    public function test_suspend_and_remove_follow_the_website(): void
    {
        $site = $this->site();
        $this->job($site);
        $site->update(['status' => 'suspended']);
        $this->assertTrue($this->calls('cron.suspend')->last()['params']['suspended']);
        $site->update(['status' => 'active']);
        $this->assertFalse($this->calls('cron.suspend')->last()['params']['suspended']);
        $site->update(['status' => 'removing']);
        $this->assertCount(1, $this->calls('cron.remove'));
        $this->assertSame(0, CronJob::count());
    }

    public function test_menu_and_domain_page_tile(): void
    {
        $site = $this->site();
        $this->client($site->customer);
        $this->get('/dashboard')->assertOk()->assertSee(route('client.cron.index'), false);
        $this->get(route('client.websites.show', [$site, 'tab' => 'tools']))->assertOk()->assertSee('Run tasks on a schedule');
    }
}
