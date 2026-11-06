<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_backup_page_renders_with_status_targets_and_restore_points(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/backups')->assertOk()
            ->assertSee('nas.example.test')
            ->assertSee('a1b2c300')
            ->assertSee(route('admin.backups.restore'), false);
        $this->get('/admin/backups?target=nas&scope=site:example.test')->assertOk();
        $this->get('/admin')->assertSee(route('admin.backups.index'), false);
    }

    public function test_admin_page_survives_an_unreachable_agent(): void
    {
        $this->actingAsAdmin();
        $this->agent()->respond('backup.status', AgentException::unreachable('Agent down'));

        $this->get('/admin/backups')->assertOk()->assertSee('Agent down');
    }

    public function test_run_starts_a_job_with_validated_params(): void
    {
        $this->actingAsAdmin();

        $res = $this->post('/admin/backups/run', ['kind' => 'full', 'target' => 'nas', 'scope' => 'sites']);
        $res->assertRedirect();
        $this->assertStringContainsString('/admin/backups/jobs/', $res->headers->get('Location'));
        $call = collect($this->agent()->calls)->firstWhere('action', 'backup.run');
        $this->assertSame(['full' => true, 'prune' => true, 'target' => 'nas', 'scopes' => ['sites']], $call['params']);
        $this->assertTrue(ActivityLog::where('action', 'backup.started')->exists());

        $this->post('/admin/backups/run', ['kind' => 'full', 'scope' => 'site:../../etc'])->assertSessionHasErrors('scope');
        $this->post('/admin/backups/run', ['kind' => 'sometimes'])->assertSessionHasErrors('kind');
    }

    public function test_job_page_refreshes_while_running(): void
    {
        $this->actingAsAdmin();
        $this->agent()->respond('job.status', ['id' => 'x', 'status' => 'running', 'log' => ['whp-backup run'], 'result' => null, 'error' => null]);

        $this->get('/admin/backups/jobs/'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12))
            ->assertOk()->assertSee('http-equiv="refresh"', false)->assertSee('whp-backup run');
        $this->get('/admin/backups/jobs/not-a-job')->assertNotFound();
    }

    public function test_restore_in_place_and_copy(): void
    {
        $this->actingAsAdmin();
        $id = str_repeat('ab', 32);

        $this->post('/admin/backups/restore', ['target' => 'nas', 'snapshot' => $id, 'scope' => 'site:example.test', 'mode' => 'in_place'])->assertRedirect();
        $this->post('/admin/backups/restore', ['target' => 'nas', 'snapshot' => $id, 'scope' => 'db:shop', 'mode' => 'copy'])->assertRedirect();
        $calls = collect($this->agent()->calls)->where('action', 'backup.restore')->values();
        $this->assertSame('in_place', $calls[0]['params']['mode']);
        $this->assertSame('/root/restore/abababab', $calls[1]['params']['to']);

        $this->post('/admin/backups/restore', ['target' => 'nas', 'snapshot' => 'abc;rm', 'scope' => 'system', 'mode' => 'in_place'])
            ->assertSessionHasErrors('snapshot');
        $this->post('/admin/backups/restore', ['target' => 'nas', 'snapshot' => $id, 'scope' => 'system', 'mode' => 'in_place', 'include' => '/var/www/../etc'])
            ->assertSessionHasErrors('include');
    }

    public function test_add_target_shows_the_new_repository_key_once_and_never_logs_secrets(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/backups/targets', [
            'name' => 'nas', 'type' => 'sftp', 'host' => 'nas.example.test', 'port' => '2222', 'user' => 'backup',
            'password' => 'S3cret!', 'path' => 'whp/web01',
        ])->assertRedirect()->assertSessionHas('repository_key', ['target' => 'nas', 'key' => 'fake-repository-key']);

        $call = collect($this->agent()->calls)->firstWhere('action', 'backup.target_add');
        $this->assertSame(2222, $call['params']['port']);
        $this->assertSame('S3cret!', $call['params']['password']);
        $this->assertStringNotContainsString('S3cret!', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_add_target_validation(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/backups/targets', ['name' => 'Bad Name', 'type' => 'sftp', 'path' => 'x'])->assertSessionHasErrors(['name', 'host', 'user']);
        $this->post('/admin/backups/targets', ['name' => 'nas', 'type' => 'sftp', 'host' => 'h', 'user' => 'u', 'path' => 'x'])->assertSessionHasErrors('ssh_key');
        $this->post('/admin/backups/targets', ['name' => 'ftp', 'type' => 'ftps', 'host' => 'h', 'user' => 'u', 'path' => 'x'])->assertSessionHasErrors('password');
        $this->post('/admin/backups/targets', ['name' => 'od', 'type' => 'onedrive', 'path' => 'x', 'onedrive_token' => 'not json'])->assertSessionHasErrors('onedrive_token');
        $this->post('/admin/backups/targets', ['name' => 'smb', 'type' => 'smb', 'path' => 'x'])->assertSessionHasErrors('type');
    }

    public function test_schedule_is_saved_through_the_agent(): void
    {
        $this->actingAsAdmin();

        $this->put('/admin/backups/schedule', [
            'incremental_enabled' => '1', 'incremental_time' => '01:15', 'full_enabled' => '0', 'full_day' => 'Sat', 'full_time' => '04:00',
        ])->assertRedirect()->assertSessionHas('status');
        $call = collect($this->agent()->calls)->firstWhere('action', 'backup.schedule');
        $this->assertSame(['incremental_enabled' => true, 'incremental_time' => '01:15', 'full_enabled' => false, 'full_day' => 'Sat', 'full_time' => '04:00'], $call['params']);

        $this->put('/admin/backups/schedule', ['incremental_time' => '25:00', 'full_day' => 'Sun', 'full_time' => '03:00'])->assertSessionHasErrors('incremental_time');
    }

    public function test_clients_cannot_open_admin_backups(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->get('/admin/backups')->assertForbidden();
        $this->actingAs($user)->post('/admin/backups/run', ['kind' => 'full'])->assertForbidden();
    }

    public function test_client_sees_and_restores_only_their_own_sites(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $other = Customer::create(['name' => 'Other', 'email' => 'other@example.test']);
        $site = Website::create(['customer_id' => $mine->id, 'domain' => 'mine.nl', 'status' => 'active']);
        $foreign = Website::create(['customer_id' => $other->id, 'domain' => 'other.nl', 'status' => 'active']);
        $user = User::factory()->create(['customer_id' => $mine->id]);

        $this->actingAs($user)->get('/backups')->assertOk()->assertSee('mine.nl')->assertDontSee('other.nl');
        $this->assertSame('site:mine.nl', collect($this->agent()->calls)->firstWhere('action', 'backup.list')['params']['scope']);

        $res = $this->actingAs($user)->post('/backups/restore', ['website' => $site->id, 'snapshot' => 'a1b2c301']);
        $res->assertRedirect();
        $restore = collect($this->agent()->calls)->firstWhere('action', 'backup.restore');
        $this->assertSame('in_place', $restore['params']['mode']);
        $this->assertSame(str_pad('a1b2c301', 64, '0'), $restore['params']['snapshot']);
        $this->actingAs($user)->get($res->headers->get('Location'))->assertOk();

        $this->actingAs($user)->post('/backups/restore', ['website' => $foreign->id, 'snapshot' => 'a1b2c301'])->assertNotFound();
        $this->actingAs($user)->post('/backups/restore', ['website' => $site->id, 'snapshot' => 'deadbeef'])->assertNotFound();
    }

    public function test_client_cannot_follow_someone_elses_job(): void
    {
        $a = Customer::create(['name' => 'A', 'email' => 'a@example.test']);
        $b = Customer::create(['name' => 'B', 'email' => 'b@example.test']);
        $site = Website::create(['customer_id' => $a->id, 'domain' => 'a.nl', 'status' => 'active']);
        $userA = User::factory()->create(['customer_id' => $a->id]);
        $userB = User::factory()->create(['customer_id' => $b->id]);

        $job = $this->actingAs($userA)->post('/backups/restore', ['website' => $site->id, 'snapshot' => 'a1b2c300'])->headers->get('Location');
        $this->actingAs($userB)->get($job)->assertNotFound();
    }

    public function test_customer_restore_can_be_turned_off(): void
    {
        config(['backups.customer_restore' => false]);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->get('/backups')->assertNotFound();
    }
}
