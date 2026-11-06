<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;
use Tests\TestCase;

class ClientBackupsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $this->user = User::factory()->create(['customer_id' => $this->customer->id]);
        $this->site = Website::create(['customer_id' => $this->customer->id, 'domain' => 'klant.nl', 'status' => 'active']);
        config(['backups.upload_dir' => storage_path('framework/testing/backup-uploads-'.uniqid())]);
    }

    private function target(array $attrs = []): ClientBackupTarget
    {
        return ClientBackupTarget::create($attrs + [
            'customer_id' => $this->customer->id, 'name' => 'NAS', 'type' => 'sftp', 'host' => 'nas.klant.nl', 'username' => 'backup',
            'password' => 'Geheim!1', 'host_key' => 'nas.klant.nl ssh-ed25519 AAAAC3Nza', 'schedule' => 'daily', 'schedule_time' => '03:00',
            'full_day' => 'Sun', 'keep_full' => 4, 'parts' => ['files', 'databases'],
        ]);
    }

    public function test_overview_shows_locations_and_empty_state(): void
    {
        $this->actingAs($this->user)->get('/backups')->assertOk()->assertSee('No backup location yet');
        $this->target();
        $this->actingAs($this->user)->get('/backups')->assertOk()->assertSee('NAS')->assertSee(route('client.backups.run'), false);
        $this->actingAs($this->user)->get('/backups?tab=upload')->assertOk()->assertSee('backupUpload');
    }

    public function test_adding_a_location_tests_it_pins_the_host_key_and_encrypts_the_password(): void
    {
        $res = $this->actingAs($this->user)->post('/backups/locations', [
            'name' => 'Thuis NAS', 'type' => 'sftp', 'host' => 'nas.klant.nl', 'port' => '2222', 'username' => 'backup',
            'password' => 'S3cret!', 'schedule' => 'daily', 'schedule_time' => '02:15', 'full_day' => 'Sat', 'keep_full' => 3,
            'parts' => ['files', 'databases'],
        ]);
        $target = ClientBackupTarget::firstOrFail();
        $res->assertRedirect(route('client.backups.locations.show', $target->id));
        $this->assertSame($this->customer->id, $target->customer_id);
        $this->assertSame('S3cret!', $target->password);
        $this->assertStringContainsString('ssh-ed25519', $target->host_key);
        $this->assertNotNull($target->tested_at);
        $raw = \DB::table('client_backup_targets')->value('password');
        $this->assertStringNotContainsString('S3cret!', $raw);

        $call = collect($this->agent()->calls)->firstWhere('action', 'client_backup.target_test');
        $this->assertSame(['type' => 'sftp', 'host' => 'nas.klant.nl', 'port' => 2222, 'user' => 'backup', 'password' => 'S3cret!', 'tls' => 'explicit'],
            array_diff_key($call['params']['target'], ['allow_plain_ftp' => 1, 'path' => 1]));
        $this->assertStringNotContainsString('S3cret!', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_a_location_that_does_not_work_is_not_saved(): void
    {
        $this->agent()->respond('client_backup.target_test', AgentException::unreachable('Connection refused'));
        $this->actingAs($this->user)->post('/backups/locations', [
            'name' => 'Kapot', 'type' => 'ftps', 'host' => 'ftp.klant.nl', 'username' => 'u', 'password' => 'p',
            'schedule' => 'off', 'schedule_time' => '03:00', 'full_day' => 'Sun', 'keep_full' => 2, 'parts' => ['files'],
        ])->assertSessionHasErrors('agent');
        $this->assertSame(0, ClientBackupTarget::count());
    }

    public function test_location_validation(): void
    {
        $base = ['name' => 'X', 'host' => 'h.nl', 'username' => 'u', 'schedule' => 'off', 'schedule_time' => '03:00', 'full_day' => 'Sun', 'keep_full' => 2, 'parts' => ['files']];
        $this->actingAs($this->user)->post('/backups/locations', $base + ['type' => 'ftp', 'password' => 'p'])->assertSessionHasErrors('allow_plain_ftp');
        $this->actingAs($this->user)->post('/backups/locations', $base + ['type' => 'ftps'])->assertSessionHasErrors('password');
        $this->actingAs($this->user)->post('/backups/locations', $base + ['type' => 'sftp'])->assertSessionHasErrors('ssh_key');
        $this->actingAs($this->user)->post('/backups/locations', $base + ['type' => 'sftp', 'password' => 'p', 'path' => '../../etc'])->assertSessionHasErrors('path');
        $this->actingAs($this->user)->post('/backups/locations', $base + ['type' => 'smb', 'password' => 'p'])->assertSessionHasErrors('type');
        $this->actingAs($this->user)->post('/backups/locations', ['host' => 'h.nl;rm'] + $base + ['type' => 'sftp', 'password' => 'p'])->assertSessionHasErrors('host');
        $this->assertSame(0, ClientBackupTarget::count());
    }

    public function test_editing_keeps_secrets_and_forgets_the_host_key_when_the_server_changes(): void
    {
        $t = $this->target();
        $this->actingAs($this->user)->put("/backups/locations/{$t->id}", [
            'name' => 'NAS', 'type' => 'sftp', 'host' => 'nieuw.klant.nl', 'username' => 'backup', 'schedule' => 'weekly',
            'schedule_time' => '04:00', 'full_day' => 'Sun', 'keep_full' => 4, 'parts' => ['files'],
        ])->assertRedirect();
        $t->refresh();
        $this->assertSame('Geheim!1', $t->password);
        $this->assertStringStartsWith('nieuw.klant.nl', $t->host_key);
        $test = collect($this->agent()->calls)->where('action', 'client_backup.target_test')->last();
        $this->assertArrayNotHasKey('host_key', $test['params']['target'], 'a new server must not be checked against the old key');
    }

    public function test_other_customers_cannot_see_or_use_a_location(): void
    {
        $t = $this->target();
        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'B', 'email' => 'b@example.test'])->id]);
        $this->actingAs($other)->get("/backups/locations/{$t->id}")->assertNotFound();
        $this->actingAs($other)->get("/backups/locations/{$t->id}/edit")->assertNotFound();
        $this->actingAs($other)->post('/backups/run', ['target' => $t->id, 'website' => $this->site->id, 'kind' => 'full'])->assertNotFound();
        $this->actingAs($other)->delete("/backups/locations/{$t->id}")->assertNotFound();
        $this->assertSame(1, ClientBackupTarget::count());
    }

    public function test_back_up_now_passes_the_sites_databases_and_records_the_job(): void
    {
        $t = $this->target();
        SiteDatabase::create(['website_id' => $this->site->id, 'name' => 'klant_wp_1a2b', 'users' => ['klant_wp_1a2b']]);
        $foreign = Website::create(['customer_id' => Customer::create(['name' => 'B', 'email' => 'b@example.test'])->id, 'domain' => 'ander.nl']);

        $this->actingAs($this->user)->post('/backups/run', ['target' => $t->id, 'website' => $foreign->id, 'kind' => 'full'])->assertNotFound();
        $this->actingAs($this->user)->post('/backups/run', ['target' => $t->id, 'website' => $this->site->id, 'kind' => 'incremental'])
            ->assertRedirect(route('client.backups.index'))->assertSessionHas('status');
        $call = collect($this->agent()->calls)->firstWhere('action', 'client_backup.run');
        $this->assertSame('klant.nl', $call['params']['domain']);
        $this->assertSame($t->id, $call['params']['target_id']);
        $this->assertSame('incremental', $call['params']['kind']);
        $this->assertSame(['files', 'databases'], $call['params']['parts']);
        $this->assertSame([['name' => 'klant_wp_1a2b', 'user' => 'klant_wp_1a2b']], $call['params']['databases']);
        $record = ClientBackup::firstOrFail();
        $this->assertSame('running', $record->status);

        // The job finishes: the record follows.
        $this->agent()->respond('job.status', ['id' => $record->job_id, 'status' => 'succeeded', 'finished_at' => time(), 'log' => [],
            'result' => ['file' => 'klant.nl-20261106-030000-full.zip', 'kind' => 'full', 'size' => 12345], 'error' => null]);
        Artisan::call('backups:client-tick');
        $record->refresh();
        $this->assertSame('succeeded', $record->status);
        $this->assertSame('full', $record->kind);
        $this->assertSame(12345, $record->size);
    }

    public function test_location_page_lists_backups_and_restores_with_a_safety_backup(): void
    {
        $t = $this->target();
        $this->actingAs($this->user)->get("/backups/locations/{$t->id}")->assertOk()->assertSee('klant.nl-')->assertSee('Restore this backup');

        $file = 'klant.nl-20261106-030000-incr-20261101-030000.zip';
        $res = $this->actingAs($this->user)->post("/backups/locations/{$t->id}/restore", [
            'website' => $this->site->id, 'file' => $file, 'parts' => ['files', 'databases'], 'safety' => '1',
        ]);
        $res->assertRedirect();
        $call = collect($this->agent()->calls)->firstWhere('action', 'client_backup.restore');
        $this->assertSame($file, $call['params']['file']);
        $this->assertSame($t->id, $call['params']['safety_target_id']);
        $this->actingAs($this->user)->get($res->headers->get('Location'))->assertOk();

        // Only files of this site, and no path tricks.
        $this->actingAs($this->user)->post("/backups/locations/{$t->id}/restore", ['website' => $this->site->id, 'file' => 'ander.nl-20261106-030000-full.zip', 'parts' => ['files']])->assertNotFound();
        $this->actingAs($this->user)->post("/backups/locations/{$t->id}/restore", ['website' => $this->site->id, 'file' => '../klant.nl-20261106-030000-full.zip', 'parts' => ['files']])->assertSessionHasErrors('file');
        // A second restore while one runs.
        ClientBackup::query()->update(['status' => 'running']);
        $this->actingAs($this->user)->post("/backups/locations/{$t->id}/restore", ['website' => $this->site->id, 'file' => $file, 'parts' => ['files']])->assertSessionHasErrors('agent');
    }

    public function test_chunked_upload_inspect_and_restore(): void
    {
        $zip = random_bytes(1000);
        $start = $this->actingAs($this->user)->postJson('/backups/uploads', ['name' => 'backup.zip', 'size' => strlen($zip)])->assertOk()->json();
        $id = $start['id'];

        $this->actingAs($this->user)->call('POST', "/backups/uploads/{$id}/chunk?offset=0", [], [], [], ['HTTP_ACCEPT' => 'application/json'], substr($zip, 0, 600))
            ->assertOk()->assertJson(['received' => 600]);
        $this->actingAs($this->user)->call('POST', "/backups/uploads/{$id}/chunk?offset=0", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'x')
            ->assertStatus(409)->assertJson(['received' => 600]);
        $this->actingAs($this->user)->postJson("/backups/uploads/{$id}/inspect")->assertStatus(422);
        $this->actingAs($this->user)->call('POST', "/backups/uploads/{$id}/chunk?offset=600", [], [], [], ['HTTP_ACCEPT' => 'application/json'], substr($zip, 600))
            ->assertOk()->assertJson(['received' => 1000]);
        $this->assertSame($zip, file_get_contents(config('backups.upload_dir')."/{$id}.zip"));

        $this->agent()->respond('client_backup.inspect_upload', ['domain' => 'klant.nl', 'kind' => 'full', 'created' => '2026-11-05T03:00:00Z',
            'base' => null, 'size' => 5000, 'contains' => ['files'], 'database_files' => []]);
        $this->actingAs($this->user)->postJson("/backups/uploads/{$id}/inspect")->assertOk()->assertJson(['suggested_website' => $this->site->id]);

        // Someone else cannot use this upload.
        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'B', 'email' => 'b@example.test'])->id]);
        $this->actingAs($other)->postJson("/backups/uploads/{$id}/restore", ['website' => $this->site->id, 'parts' => ['files']])->assertNotFound();

        $res = $this->actingAs($this->user)->postJson("/backups/uploads/{$id}/restore", ['website' => $this->site->id, 'parts' => ['files']])->assertOk();
        $this->assertStringContainsString('/backups/jobs/', $res->json('redirect'));
        $call = collect($this->agent()->calls)->firstWhere('action', 'client_backup.restore_upload');
        $this->assertSame(['domain' => 'klant.nl', 'upload' => $id, 'parts' => ['files'], 'databases' => []], $call['params']);
        $this->assertSame('restore_upload', ClientBackup::firstOrFail()->action);
    }

    public function test_upload_limits(): void
    {
        config(['backups.client_max_upload_gb' => 1]);
        $this->actingAs($this->user)->postJson('/backups/uploads', ['name' => 'big.zip', 'size' => 2 * 1024 ** 3])->assertStatus(422);
        $this->actingAs($this->user)->postJson('/backups/uploads', ['name' => 'site.tar.gz', 'size' => 100])->assertStatus(422);
        $id = $this->actingAs($this->user)->postJson('/backups/uploads', ['name' => 'a.zip', 'size' => 100])->json('id');
        $this->actingAs($this->user)->call('POST', "/backups/uploads/{$id}/chunk?offset=0", [], [], [], ['HTTP_ACCEPT' => 'application/json'], str_repeat('x', 200))
            ->assertStatus(422);
        $this->assertFileDoesNotExist(config('backups.upload_dir')."/{$id}.zip");
        $this->actingAs($this->user)->postJson('/backups/uploads/not-a-uuid/inspect')->assertNotFound();
    }

    public function test_schedule_due_logic(): void
    {
        $t = $this->target(['schedule' => 'daily', 'schedule_time' => '03:00', 'full_day' => 'Sun']);
        $backups = app(ClientBackups::class);
        $sunday = Carbon::parse('2026-11-08 03:10'); // a Sunday
        $this->assertSame('full', $backups->due($t, $this->site, $sunday));
        $this->assertSame('incremental', $backups->due($t, $this->site, Carbon::parse('2026-11-09 03:10')));
        $this->assertNull($backups->due($t, $this->site, Carbon::parse('2026-11-09 02:50')), 'before the time: yesterday is long past');
        ClientBackup::create(['customer_id' => $this->customer->id, 'website_id' => $this->site->id, 'client_backup_target_id' => $t->id,
            'domain' => 'klant.nl', 'action' => 'backup', 'trigger' => 'schedule', 'status' => 'succeeded', 'started_at' => $sunday]);
        $this->assertNull($backups->due($t, $this->site, $sunday->copy()->addMinutes(5)));

        $t->update(['schedule' => 'weekly']);
        $this->assertNull($backups->due($t, $this->site, Carbon::parse('2026-11-09 03:10')));
        $t->update(['schedule' => 'off']);
        $this->assertNull($backups->due($t, $this->site, Carbon::parse('2026-11-15 03:10')));
    }

    public function test_tick_starts_scheduled_backups_once(): void
    {
        $t = $this->target(['schedule' => 'daily', 'schedule_time' => now()->subMinutes(10)->format('H:i')]);
        Artisan::call('backups:client-tick');
        Artisan::call('backups:client-tick');
        $this->assertSame(1, collect($this->agent()->calls)->where('action', 'client_backup.run')->count());
        $this->assertSame('schedule', ClientBackup::firstOrFail()->trigger);
    }

    public function test_customer_backups_can_be_turned_off(): void
    {
        config(['backups.customer_targets' => false]);
        $this->actingAs($this->user)->get('/backups/locations/create')->assertNotFound();
        $this->actingAs($this->user)->postJson('/backups/uploads', ['name' => 'a.zip', 'size' => 100])->assertNotFound();
    }
}
