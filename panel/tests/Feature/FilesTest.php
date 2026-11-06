<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FilesTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = storage_path('framework/testing/files-spool-'.getmypid());
        config(['files.spool_dir' => $this->spool]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function client(bool $ssh = false): array
    {
        $package = HostingPackage::create(['name' => $ssh ? 'Pro' : 'Basis', 'allow_ssh' => $ssh]);
        $customer = Customer::create(['name' => 'Klant', 'email' => 'info@klant.nl']);
        $site = $this->withPackage(Website::create(['customer_id' => $customer->id, 'domain' => 'klant.nl', 'status' => 'active', 'system_user' => 's_klant_nl']), $package);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        return [$user, $site, $customer];
    }

    private function listing(): array
    {
        return ['path' => '', 'entries' => [
            ['name' => 'public', 'type' => 'dir', 'size' => null, 'mtime' => 1762400000, 'mode' => '0755'],
            ['name' => 'logs', 'type' => 'dir', 'size' => null, 'mtime' => 1762400000, 'mode' => '0750'],
        ], 'total' => 2, 'truncated' => false, 'free_bytes' => 1024 ** 3, 'protected' => ['logs', 'tmp', 'sessions', 'public'], 'docroot' => 'public'];
    }

    public function test_single_site_customer_goes_straight_to_the_browser(): void
    {
        [$user, $site] = $this->client();
        $this->actingAs($user)->get('/files')->assertRedirect("/files/{$site->id}");
        $this->actingAs($user)->get("/files/{$site->id}")->assertOk()->assertSee('klant.nl')->assertSee('fileManager', false)
            ->assertDontSee(route('client.files.terminal', $site));
        $this->actingAs($user)->get('/dashboard')->assertSee('File manager');
    }

    public function test_customer_cannot_open_someone_elses_site(): void
    {
        [$user] = $this->client();
        $other = Website::create(['customer_id' => Customer::create(['name' => 'Ander', 'email' => 'a@b.nl'])->id, 'domain' => 'ander.nl', 'status' => 'active']);
        foreach (["/files/{$other->id}", "/files/{$other->id}/list", "/files/{$other->id}/read?path=public/index.php", "/files/{$other->id}/download?path=public"] as $url) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }
        $this->actingAs($user)->postJson("/files/{$other->id}/delete", ['paths' => ['public']])->assertNotFound();
        $this->assertSame([], preg_grep('/^(files|terminal)\./', array_column($this->agent()->calls, 'action')));
    }

    public function test_list_passes_clean_paths_and_refuses_traversal(): void
    {
        [$user, $site] = $this->client();
        $this->agent()->respond('files.list', fn ($p) => $this->listing() + ['asked' => $p['path']]);
        $this->actingAs($user)->getJson("/files/{$site->id}/list?path=/public//css/")->assertOk()->assertJsonPath('asked', 'public/css');
        $this->actingAs($user)->getJson("/files/{$site->id}/list?path=public/../../etc")->assertStatus(400);
        $this->assertSame('klant.nl', $this->agent()->calls[0]['params']['domain']);
    }

    public function test_editor_keeps_whitespace_and_empty_files(): void
    {
        [$user, $site] = $this->client();
        $seen = [];
        $this->agent()->respond('files.write', function ($p) use (&$seen) {
            $seen[] = file_get_contents($this->spool.'/'.$p['spool']);

            return ['path' => $p['path'], 'size' => 0, 'mtime' => 1];
        });
        $this->actingAs($user)->putJson("/files/{$site->id}/write", ['path' => 'a.py', 'content' => "  indented\n\n"])->assertOk();
        $this->actingAs($user)->putJson("/files/{$site->id}/write", ['path' => 'empty.txt', 'content' => ''])->assertOk();
        $this->assertSame(["  indented\n\n", ''], $seen);
    }

    public function test_editor_reads_and_saves_through_the_spool(): void
    {
        [$user, $site] = $this->client();
        $this->agent()->respond('files.read', function ($p) {
            file_put_contents($this->spool.'/'.$p['spool'], "<?php echo 'hoi';\n");

            return ['path' => $p['path'], 'size' => 18, 'mtime' => '1762420000123456789', 'mode' => '0644'];
        });
        $written = null;
        $this->agent()->respond('files.write', function ($p) use (&$written) {
            $written = file_get_contents($this->spool.'/'.$p['spool']);
            $this->assertSame('1762420000123456789', $p['expected_mtime']);

            return ['path' => $p['path'], 'size' => strlen($written), 'mtime' => 222];
        });

        $this->actingAs($user)->get("/files/{$site->id}/edit?path=public/index.php")->assertOk()->assertSee('codeEditor', false)->assertSee('PHP');
        $this->actingAs($user)->getJson("/files/{$site->id}/read?path=public/index.php")
            ->assertOk()->assertJsonPath('content', "<?php echo 'hoi';\n")->assertJsonPath('language', 'PHP');
        $this->actingAs($user)->putJson("/files/{$site->id}/write", ['path' => 'public/index.php', 'content' => "<?php echo 'nieuw';\n", 'expected_mtime' => '1762420000123456789'])
            ->assertOk()->assertJsonPath('mtime', 222);
        $this->assertSame("<?php echo 'nieuw';\n", $written);
        $this->assertSame([], glob($this->spool.'/*'), 'spool files are cleaned up');
        $this->assertTrue(ActivityLog::where('action', 'files.edit')->exists());
    }

    public function test_conflict_and_binary_errors_become_readable_json(): void
    {
        [$user, $site] = $this->client();
        $this->agent()->respond('files.write', new AgentException('conflict', 'The file was changed by someone else since you opened it', ['reason' => 'conflict']));
        $this->agent()->respond('files.read', new AgentException('invalid_params', 'This is not a UTF-8 text file; download it instead', ['reason' => 'binary']));
        $this->actingAs($user)->putJson("/files/{$site->id}/write", ['path' => 'a.php', 'content' => 'x', 'expected_mtime' => '1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->actingAs($user)->getJson("/files/{$site->id}/read?path=logo.png")->assertStatus(422)->assertJsonPath('error.code', 'binary');
    }

    public function test_chunked_upload_is_assembled_and_handed_to_the_agent(): void
    {
        [$user, $site] = $this->client();
        $received = null;
        $this->agent()->respond('files.upload', function ($p) use (&$received) {
            $received = $p + ['data' => file_get_contents($this->spool.'/'.$p['spool'])];

            return ['path' => $p['dir'].'/'.$p['name'], 'size' => strlen($received['data'])];
        });
        $base = ['upload_id' => 'abcdef12-3456', 'total' => 2, 'dir' => 'public', 'name' => 'img/logo.svg'];
        $this->actingAs($user)->post("/files/{$site->id}/upload", $base + ['index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk', 'deel1-')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('done', false);
        $this->assertNull($received);
        $this->actingAs($user)->post("/files/{$site->id}/upload", $base + ['index' => 1, 'chunk' => UploadedFile::fake()->createWithContent('chunk', 'deel2')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('done', true)->assertJsonPath('path', 'public/img/logo.svg');
        $this->assertSame('deel1-deel2', $received['data']);
        $this->assertSame('klant.nl', $received['domain']);
        $this->assertFalse($received['overwrite']);

        // Chunks out of order are refused.
        $this->actingAs($user)->post("/files/{$site->id}/upload", ['upload_id' => 'other-upload-1', 'index' => 3, 'total' => 5, 'dir' => '', 'name' => 'x',
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'x')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_download_streams_the_spool_file_and_removes_it(): void
    {
        [$user, $site] = $this->client();
        $this->agent()->respond('files.download', function ($p) {
            file_put_contents($this->spool.'/'.$p['spool'], 'ZIPDATA');

            return ['name' => 'public.zip', 'type' => 'zip'];
        });
        $res = $this->actingAs($user)->get("/files/{$site->id}/download?path=public");
        $res->assertOk()->assertDownload('public.zip');
        $this->assertSame('ZIPDATA', $res->streamedContent() ?: file_get_contents($res->baseResponse->getFile()->getPathname()));
    }

    public function test_jobs_belong_to_the_user_that_started_them(): void
    {
        [$user, $site] = $this->client();
        $job = '0b8c1e9e-1111-4222-8333-944445555666';
        $this->agent()->respond('files.extract', ['job' => $job]);
        $this->agent()->respond('job.status', ['id' => $job, 'status' => 'succeeded', 'log' => ['12 files unpacked'], 'result' => ['files' => 12], 'error' => null]);
        $this->actingAs($user)->postJson("/files/{$site->id}/extract", ['path' => 'public/site.zip', 'to' => 'public'])->assertOk()->assertJsonPath('job', $job);
        $this->actingAs($user)->getJson("/files/{$site->id}/jobs/{$job}")->assertOk()->assertJsonPath('status', 'succeeded');

        [$other] = [User::factory()->create(['customer_id' => $site->customer_id])];
        $this->actingAs($other)->getJson("/files/{$site->id}/jobs/{$job}")->assertNotFound();
        $this->actingAs($user)->getJson("/files/{$site->id}/jobs/0b8c1e9e-1111-4222-8333-000000000000")->assertNotFound();
    }

    public function test_terminal_only_with_ssh_package_and_auth_checks_token_owner(): void
    {
        [$user, $site] = $this->client(ssh: false);
        $this->agent()->respond('terminal.status', ['available' => true]);
        $this->actingAs($user)->get("/files/{$site->id}/terminal")->assertNotFound();
        $this->actingAs($user)->post("/files/{$site->id}/terminal")->assertNotFound();

        $site->package->update(['allow_ssh' => true]);
        $user = $user->fresh();
        $token = str_repeat('ab', 16);
        $this->agent()->respond('terminal.start', ['token' => $token, 'path' => "/terminal/{$token}/", 'expires_in' => 3600]);
        $this->actingAs($user)->get("/files/{$site->id}")->assertSee(route('client.files.terminal', $site));
        $this->actingAs($user)->post("/files/{$site->id}/terminal")->assertRedirect("/files/{$site->id}/terminal");
        $this->actingAs($user)->get("/files/{$site->id}/terminal")->assertOk()->assertSee("/terminal/{$token}/");

        $this->actingAs($user)->get('/terminal/auth', ['X-Original-URI' => "/terminal/{$token}/ws"])->assertNoContent();
        $intruder = User::factory()->create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@x.nl'])->id]);
        $this->actingAs($intruder)->get('/terminal/auth', ['X-Original-URI' => "/terminal/{$token}/ws"])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/terminal/auth', ['X-Original-URI' => "/terminal/{$token}/ws"])->assertUnauthorized();

        $this->actingAs($user)->delete("/files/{$site->id}/terminal")->assertRedirect();
        $this->assertContains('terminal.stop', array_column($this->agent()->calls, 'action'));
        $this->actingAs($user)->get('/terminal/auth', ['X-Original-URI' => "/terminal/{$token}/ws"])->assertForbidden();
    }

    public function test_admin_can_browse_every_site(): void
    {
        [, $site] = $this->client();
        $this->actingAsAdmin();
        $this->agent()->respond('files.list', $this->listing());
        $this->get('/admin/files')->assertOk()->assertSee('klant.nl');
        $this->get("/admin/files/{$site->id}")->assertOk();
        $this->getJson("/admin/files/{$site->id}/list")->assertOk()->assertJsonPath('docroot', 'public');
    }

    public function test_dutch_translation(): void
    {
        [$user, $site] = $this->client();
        $user->update(['locale' => 'nl']);
        $this->actingAs($user)->get("/files/{$site->id}")->assertSee('Bestandsbeheer')->assertSee('Nieuwe map');
    }
}
