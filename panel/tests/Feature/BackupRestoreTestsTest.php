<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Rhis\Backups\Mail\RestoreTestReport;
use Rhis\Backups\Models\RestoreTest;
use Rhis\Backups\RestoreTests\RestoreTester;
use Rhis\Backups\RestoreTests\RestoreTestOptions;
use Tests\TestCase;

class BackupRestoreTestsTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $status = 'ok', array $override = []): array
    {
        $check = fn ($c, $r = 'ok', $d = 'fine') => ['check' => $c, 'result' => $r, 'detail' => $d];

        return array_replace_recursive([
            'status' => $status, 'host' => 'web01', 'kinds' => 'both', 'started' => now()->subMinutes(4)->toIso8601String(),
            'finished' => now()->toIso8601String(), 'sandbox_removed' => true,
            'targets' => [[
                'target' => 'nas', 'type' => 'sftp', 'status' => $status,
                'items' => [
                    ['scope' => 'site:klant.nl', 'snapshot' => str_repeat('a', 64), 'short_id' => 'aaaaaaaa', 'kind' => 'incremental',
                        'time' => now()->subHours(3)->toIso8601String(), 'age_hours' => 3, 'picked' => 'latest', 'duration_ms' => 1200,
                        'status' => $status === 'failed' ? 'failed' : 'ok', 'files' => 120, 'bytes' => 4_500_000,
                        'checks' => [$check('restore'), $check('complete', $status === 'failed' ? 'failed' : 'ok', 'restored 118 of 120 files')]],
                    ['scope' => 'db:klant_wp', 'snapshot' => str_repeat('b', 64), 'short_id' => 'bbbbbbbb', 'kind' => 'full',
                        'time' => now()->subDays(2)->toIso8601String(), 'age_hours' => 48, 'picked' => 'latest', 'duration_ms' => 900,
                        'status' => 'ok', 'bytes' => 20000, 'tables' => 12,
                        'checks' => [$check('restore'), $check('dump_complete'), $check('import'), $check('tables')]],
                ],
                'coverage' => ['items' => 5, 'with_backup' => 5, 'missing' => [], 'stale' => []],
            ]],
        ], $override);
    }

    private function finishedJob(array $report): void
    {
        $this->agent()->respond('job.status', ['id' => 'x', 'status' => 'succeeded', 'log' => [], 'result' => $report, 'error' => null]);
    }

    public function test_page_renders_empty_and_with_a_report(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/backups/restore-tests')->assertOk()->assertSee('No restore test yet')
            ->assertSee(route('admin.backups.restore-tests.settings'), false);

        $run = RestoreTest::create(['trigger' => 'schedule', 'status' => 'running', 'job_id' => 'j1', 'started_at' => now()->subMinutes(5)]);
        app(RestoreTester::class)->finish($run, $this->report('failed'));

        $this->get('/admin/backups/restore-tests')->assertOk()->assertSee('Some backups cannot be restored');
        $this->get(route('admin.backups.restore-tests.show', $run))->assertOk()
            ->assertSee('Website klant.nl')->assertSee('restored 118 of 120 files')->assertSee('Database klant_wp');
        // The Backups overview links to the restore tests and the menu shows a warning badge.
        $this->get('/admin/backups')->assertOk()->assertSee(route('admin.backups.restore-tests.index'), false);
    }

    public function test_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']))->withSession(['two_factor_passed' => true]);
        $this->get('/admin/backups/restore-tests')->assertForbidden();
    }

    public function test_test_now_starts_an_agent_job_with_the_settings(): void
    {
        $this->actingAsAdmin();
        app(RestoreTestOptions::class)->save(['sites' => 4, 'databases' => 0, 'kinds' => 'latest']);

        $res = $this->post('/admin/backups/restore-tests', ['target' => 'nas']);
        $run = RestoreTest::first();
        $res->assertRedirect(route('admin.backups.restore-tests.show', $run));
        $call = collect($this->agent()->calls)->firstWhere('action', 'backup.verify');
        $this->assertSame(['target' => 'nas', 'system' => true, 'sites' => 4, 'mail' => 1, 'databases' => 0, 'kinds' => 'latest', 'max_age_hours' => 36], $call['params']);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('running', $run->status);

        // Only one at a time.
        $this->post('/admin/backups/restore-tests')->assertSessionHasErrors('agent');
        $this->post('/admin/backups/restore-tests', ['target' => '../x'])->assertSessionHasErrors('target');
    }

    public function test_settings_are_validated_and_saved(): void
    {
        $this->actingAsAdmin();
        $ok = ['enabled' => 1, 'frequency' => 'daily', 'day' => 'Mon', 'monthday' => 1, 'time' => '05:15', 'target' => '', 'system' => 0,
            'sites' => 3, 'mail' => 2, 'databases' => 5, 'kinds' => 'both', 'max_age_hours' => 30, 'notify' => 'warnings', 'mail_to' => 'a@example.nl, b@example.nl'];
        $this->put('/admin/backups/restore-tests/settings', $ok)->assertSessionHasNoErrors();
        $o = app(RestoreTestOptions::class);
        $this->assertSame('daily', $o->get('frequency'));
        $this->assertSame('0', $o->get('system'));
        $this->assertSame(['a@example.nl', 'b@example.nl'], $o->recipients());

        $this->put('/admin/backups/restore-tests/settings', ['mail_to' => 'nope'] + $ok)->assertSessionHasErrors('mail_to');
        $this->put('/admin/backups/restore-tests/settings', ['frequency' => 'hourly'] + $ok)->assertSessionHasErrors('frequency');
        $this->put('/admin/backups/restore-tests/settings', ['sites' => 500] + $ok)->assertSessionHasErrors('sites');
    }

    public function test_schedule_slots(): void
    {
        $o = app(RestoreTestOptions::class);
        $now = Carbon::parse('2026-11-06 10:00'); // Friday
        $o->save(['frequency' => 'weekly', 'day' => 'Wed', 'time' => '06:00']);
        $this->assertSame('2026-11-04 06:00', $o->lastSlot($now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 06:00', $o->nextSlot($now)->format('Y-m-d H:i'));
        $o->save(['day' => 'Fri']);
        $this->assertSame('2026-11-06 06:00', $o->lastSlot($now)->format('Y-m-d H:i'));
        $o->save(['day' => 'Sun']);
        $this->assertSame('2026-11-01 06:00', $o->lastSlot($now)->format('Y-m-d H:i'));
        $o->save(['frequency' => 'daily', 'time' => '11:00']);
        $this->assertSame('2026-11-05 11:00', $o->lastSlot($now)->format('Y-m-d H:i'));
        $o->save(['frequency' => 'monthly', 'monthday' => 10, 'time' => '06:00']);
        $this->assertSame('2026-10-10 06:00', $o->lastSlot($now)->format('Y-m-d H:i'));
        $o->save(['enabled' => false]);
        $this->assertNull($o->nextSlot($now));
    }

    public function test_tick_starts_a_scheduled_run_once_and_collects_it(): void
    {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'admin@example.nl']);
        app(RestoreTestOptions::class)->save(['frequency' => 'daily', 'time' => '06:00']);
        $this->travelTo(Carbon::parse('2026-11-06 06:03'));
        $tester = app(RestoreTester::class);

        $run = $tester->tick();
        $this->assertSame('running', $run->status);
        $this->assertSame('2026-11-06 06:00', $run->slot_at->format('Y-m-d H:i'));
        $this->agent()->respond('job.status', ['id' => 'x', 'status' => 'running', 'log' => [], 'result' => null, 'error' => null]);
        $this->travel(5)->minutes();
        $this->assertNull($tester->tick(), 'no second run for the same slot');

        $this->finishedJob($this->report('failed'));
        $this->travel(5)->minutes();
        $tester->tick();
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(2, $run->tested);
        $this->assertSame(1, $run->problems);
        Mail::assertSent(RestoreTestReport::class, fn ($m) => $m->hasTo('admin@example.nl') && ! $m->recovered);

        // Next day it passes: a "passed again" mail.
        $this->travelTo(Carbon::parse('2026-11-07 06:01'));
        $next = $tester->tick();
        $this->finishedJob($this->report('ok'));
        $tester->collect($next);
        $this->assertSame('ok', $next->fresh()->status);
        Mail::assertSent(RestoreTestReport::class, fn ($m) => $m->recovered);

        // Day after: passing again sends nothing (notify = problems).
        $this->travelTo(Carbon::parse('2026-11-08 06:01'));
        $third = $tester->tick();
        $tester->collect($third);
        Mail::assertSentCount(2);
    }

    public function test_missed_slot_is_not_caught_up_after_the_window(): void
    {
        app(RestoreTestOptions::class)->save(['frequency' => 'daily', 'time' => '06:00']);
        $this->travelTo(Carbon::parse('2026-11-06 19:00'));
        $this->assertNull(app(RestoreTester::class)->tick());
    }

    public function test_busy_lock_postpones_and_retries(): void
    {
        app(RestoreTestOptions::class)->save(['frequency' => 'daily', 'time' => '06:00']);
        $this->travelTo(Carbon::parse('2026-11-06 06:01'));
        $tester = app(RestoreTester::class);
        $run = $tester->tick();
        $this->agent()->respond('job.status', ['id' => 'x', 'status' => 'failed', 'log' => [], 'result' => null,
            'error' => ['code' => 'conflict', 'message' => 'another whp-backup job is running']]);
        $this->travel(5)->minutes();
        $this->assertNull($tester->tick());
        $this->assertSame('busy', $run->fresh()->status);
        $this->travel(16)->minutes();
        $retry = $tester->tick();
        $this->assertNotNull($retry);
        $this->assertNotSame($run->id, $retry->id);
    }

    public function test_lost_job_uses_the_last_report_or_gives_up(): void
    {
        Mail::fake();
        User::factory()->admin()->create();
        $tester = app(RestoreTester::class);
        $run = RestoreTest::create(['trigger' => 'manual', 'status' => 'running', 'job_id' => 'gone', 'started_at' => now()->subMinutes(10)]);
        $this->agent()->respond('job.status', new AgentException('not_found', 'Job not found'));
        $this->agent()->respond('backup.verify_last', ['report' => $this->report('ok', ['started' => now()->subMinutes(9)->toIso8601String()])]);
        $this->assertSame('ok', $tester->collect($run)->fresh()->status);

        $run2 = RestoreTest::create(['trigger' => 'manual', 'status' => 'running', 'job_id' => 'gone2', 'started_at' => now()]);
        $this->agent()->respond('backup.verify_last', ['report' => null]);
        $this->assertSame('error', $tester->collect($run2)->fresh()->status);
        Mail::assertSent(RestoreTestReport::class, 1);
    }

    public function test_unreachable_agent_keeps_waiting_then_times_out(): void
    {
        $tester = app(RestoreTester::class);
        $run = RestoreTest::create(['trigger' => 'manual', 'status' => 'running', 'job_id' => 'j', 'started_at' => now()]);
        $this->agent()->respond('job.status', AgentException::unreachable('down'));
        $this->assertSame('running', $tester->collect($run)->fresh()->status);
        $this->travel(RestoreTester::MAX_HOURS + 1)->hours();
        $this->assertSame('error', $tester->collect($run->fresh())->fresh()->status);
    }

    public function test_mail_renders_in_dutch(): void
    {
        $run = RestoreTest::create(['trigger' => 'schedule', 'status' => 'running', 'job_id' => 'j', 'started_at' => now()]);
        app(RestoreTester::class)->finish($run, $this->report('failed', ['targets' => [['coverage' => ['missing' => ['site:nieuw.nl']]]]]));
        app()->setLocale('nl');
        $html = (new RestoreTestReport($run->fresh(), false, 'web01'))->render();
        $this->assertStringContainsString('Hersteltest mislukt', $html);
        $this->assertStringContainsString('Helemaal geen backup', $html);
        $this->assertStringContainsString('Website nieuw.nl', $html);
    }

    public function test_engine_details_are_translated_with_readable_sizes(): void
    {
        $d = \Rhis\Backups\RestoreTests\Labels::detail('restored 118 of 120 files, 4400000 of 4500000 bytes');
        $this->assertSame('only 118 of 120 files (4.2 MB of 4.3 MB) restored', $d);
        app()->setLocale('nl');
        $this->assertSame('dump gedownload en ontsleuteld (19.5 KB)', \Rhis\Backups\RestoreTests\Labels::detail('dump downloaded and decrypted (20000 bytes)'));
        $this->assertSame('something new', \Rhis\Backups\RestoreTests\Labels::detail('something new'));
    }

    public function test_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backups:restore-test-tick')->assertSuccessful();
    }
}
