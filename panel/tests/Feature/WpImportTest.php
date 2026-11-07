<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Models\Website;
use App\Models\WpImport;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WpImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wpi-test-'.uniqid();
        config(['wp-import.upload_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function customer(string $domain = 'klant.nl'): array
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@'.$domain]);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => $domain, 'status' => 'active', 'php_version' => '8.4', 'profile' => 'php', 'aliases' => ['www.'.$domain]]);

        return [$customer, $user, $site];
    }

    private function calls(string $action): array
    {
        return collect($this->agent()->calls)->where('action', $action)->values()->all();
    }

    /** Upload a file in one chunk through the wizard's JSON endpoints. */
    private function upload(User $user, WpImport $import, string $kind, string $name, string $data): void
    {
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/{$kind}", ['name' => $name, 'size' => strlen($data)])
            ->assertOk()->assertJson(['chunk_size' => 8 * 1024 * 1024]);
        $this->actingAs($user)->call('POST', "/import-wordpress/{$import->id}/files/{$kind}/chunk?offset=0", [], [], [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], $data)
            ->assertOk()->assertJson(['received' => strlen($data), 'complete' => true]);
    }

    private function started(User $user, Website $site): WpImport
    {
        $this->actingAs($user)->post('/import-wordpress', ['website' => $site->id])->assertRedirect();

        return WpImport::sole();
    }

    public function test_client_runs_the_whole_wizard(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->get('/import-wordpress')->assertOk()
            ->assertSee(__('Import WordPress website'))->assertSee(__('How do I make the two files?'))->assertSee('klant.nl');
        $import = $this->started($user, $site);
        $this->assertSame(['upload', 'klant.nl', $site->customer_id], [$import->status, $import->domain, $import->customer_id]);

        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()
            ->assertSee(__('Upload the two files'))->assertSee(__('Database export (zip)'))->assertSee('assets/wp-import.js');
        // Checking needs both files.
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check")->assertSessionHasErrors('import');
        $this->upload($user, $import, 'site', 'website.zip', 'PK-site');
        $this->upload($user, $import, 'database', 'export.sql.gz', 'db-dump');
        $this->assertFileExists($this->dir.'/'.$import->uuid.'-site.upload');
        $this->assertSame('db-dump', file_get_contents($this->dir.'/'.$import->uuid.'-database.upload'));

        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check")->assertRedirect("/import-wordpress/{$import->id}");
        $this->assertSame(['import' => $import->uuid, 'domain' => 'klant.nl'], $this->calls('wp_import.inspect')[0]['params']);
        $import->refresh();
        $this->assertSame('checked', $import->status);
        $this->assertTrue($import->checkPassed());

        // The confirmation shows the database connection, addresses, paths and cache files.
        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()
            ->assertSee(__('The files are complete'))->assertSee(__('Database connection'))->assertSee('klant123_wp')
            ->assertSee('klant_nl_import_7c1e')->assertSee('/home/klant123/public_html')->assertSee('http://www.klant.nl')
            ->assertSee('wp-content/object-cache.php')->assertSee(__('Import website'));

        // Nothing runs without confirming.
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/run", ['new_url' => 'https://www.klant.nl'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/run", ['new_url' => 'https://evil.example', 'confirm' => '1'])->assertSessionHasErrors('new_url');
        $this->assertSame([], $this->calls('wp_import.run'));

        $this->actingAs($user)->post("/import-wordpress/{$import->id}/run", [
            'new_url' => 'https://www.klant.nl', 'confirm' => '1', 'urls' => ['http://www.klant.nl'],
            'paths' => ['/home/klant123/public_html'], 'disable' => ['wp-content/object-cache.php'],
            'extra_old' => 'https://cdn.oud.nl', 'extra_new' => 'https://www.klant.nl',
        ])->assertRedirect("/import-wordpress/{$import->id}")->assertSessionHasNoErrors();
        $this->assertSame([
            'import' => $import->uuid, 'domain' => 'klant.nl', 'replace' => false, 'new_url' => 'https://www.klant.nl',
            'paths' => ['/home/klant123/public_html'], 'urls' => ['http://www.klant.nl'], 'disable' => ['wp-content/object-cache.php'],
            'extra' => [['https://cdn.oud.nl', 'https://www.klant.nl']],
        ], $this->calls('wp_import.run')[0]['params']);

        $import->refresh();
        $this->assertSame('done', $import->status);
        $db = SiteDatabase::where('website_id', $site->id)->sole();
        $this->assertSame(['klant_nl_import_7c1e', 'import'], [$db->name, $db->origin]);
        $this->assertSame('wordpress', $site->fresh()->profile);
        $this->assertFileDoesNotExist($this->dir.'/'.$import->uuid.'-site.upload', 'uploads are removed after the import');
        $this->assertCount(1, $this->calls('wp_import.discard'));
        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()
            ->assertSee(__('Your WordPress website is imported'))->assertSee('klant_nl_import_7c1e');
    }

    public function test_upload_rules(): void
    {
        [, $user, $site] = $this->customer();
        $import = $this->started($user, $site);
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/site", ['name' => 'site.tar.gz', 'size' => 10])
            ->assertStatus(422)->assertJson(['message' => __('Choose a .zip file.')]);
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/database", ['name' => 'dump.exe', 'size' => 10])->assertStatus(422);
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/site", ['name' => 'site.zip', 'size' => 21 * 1024 ** 3])->assertStatus(422);
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/other", ['name' => 'x.zip', 'size' => 1])->assertNotFound();

        // A chunk for the wrong offset tells the browser where to resume; too much data is refused.
        $this->actingAs($user)->postJson("/import-wordpress/{$import->id}/files/site", ['name' => 'site.zip', 'size' => 4])->assertOk();
        $this->actingAs($user)->call('POST', "/import-wordpress/{$import->id}/files/site/chunk?offset=3", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'ab')
            ->assertStatus(409)->assertJson(['received' => 0]);
        $this->actingAs($user)->call('POST', "/import-wordpress/{$import->id}/files/site/chunk?offset=0", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'abcdef')
            ->assertStatus(422);
        $this->assertFalse($import->fresh()->uploaded('site'));
    }

    public function test_other_customers_and_team_scope_cannot_open_an_import(): void
    {
        [, $user, $site] = $this->customer();
        [, $other] = $this->customer('ander.nl');
        $import = $this->started($user, $site);
        $this->actingAs($other)->get("/import-wordpress/{$import->id}")->assertNotFound();
        $this->actingAs($other)->post('/import-wordpress', ['website' => $site->id])->assertNotFound();
        $this->actingAs($other)->postJson("/import-wordpress/{$import->id}/files/site", ['name' => 'a.zip', 'size' => 3])->assertNotFound();
        $this->assertSame(1, WpImport::count());
    }

    public function test_customer_access_can_be_switched_off(): void
    {
        [, $user] = $this->customer();
        config(['wp-import.customer_access' => false]);
        $this->actingAs($user)->get('/import-wordpress')->assertNotFound();
    }

    public function test_failed_check_goes_back_to_upload_with_the_reason(): void
    {
        [, $user, $site] = $this->customer();
        $import = $this->started($user, $site);
        $this->upload($user, $import, 'site', 'website.zip', 'PK');
        $this->upload($user, $import, 'database', 'db.zip', 'PK');
        $this->agent()->respond('wp_import.inspect', ['job' => 'job-1']);
        $this->agent()->respond('job.status', ['id' => 'job-1', 'status' => 'failed', 'log' => [], 'result' => null,
            'error' => ['code' => 'invalid_params', 'message' => 'This is not a valid zip file']]);
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check")->assertRedirect();
        $this->assertSame('upload', $import->fresh()->status);
        $user->update(['locale' => 'nl']);
        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()->assertSee('Dit is geen geldig zip-bestand');
    }

    public function test_panel_checks_block_a_full_package(): void
    {
        [, $user, $site] = $this->customer();
        $package = HostingPackage::create(['name' => 'Klein', 'max_databases' => 1, 'disk_mb' => 100]);
        $site->subscription->update(['hosting_package_id' => $package->id]);
        SiteDatabase::create(['website_id' => $site->id, 'name' => 'klant_nl_wp_1', 'users' => ['klant_nl_wp_1'], 'origin' => 'app']);
        $import = $this->started($user, $site);
        $this->upload($user, $import, 'site', 'website.zip', 'PK');
        $this->upload($user, $import, 'database', 'db.zip', 'PK');
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check");
        $import->refresh();
        $this->assertSame('checked', $import->status);
        $this->assertFalse($import->checkPassed());
        $states = collect($import->report['checks'])->pluck('state', 'key');
        $this->assertSame(['bad', 'bad'], [$states['disk_space'], $states['database_limit']]);
        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()
            ->assertSee(__('The check found problems'))->assertDontSee(__('Import website'));
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/run", ['new_url' => 'https://www.klant.nl', 'confirm' => '1'])->assertSessionHasErrors('import');
    }

    public function test_failed_import_shows_retry_and_admin_sees_the_log(): void
    {
        [, $user, $site] = $this->customer();
        $import = $this->started($user, $site);
        $this->upload($user, $import, 'site', 'website.zip', 'PK');
        $this->upload($user, $import, 'database', 'db.zip', 'PK');
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check");
        $this->agent()->respond('wp_import.run', ['job' => 'job-run']);
        $this->agent()->respond('job.status', ['id' => 'job-run', 'status' => 'failed', 'log' => ['step: files', 'step: database', 'import failed: putting the website back as it was'],
            'result' => null, 'error' => ['code' => 'command_failed', 'message' => 'Importing the database failed: ERROR 1064']]);
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/run", ['new_url' => 'https://www.klant.nl', 'confirm' => '1'])->assertRedirect();
        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->actingAs($user)->get("/import-wordpress/{$import->id}")->assertOk()
            ->assertSee(__('The import failed. :domain was put back as it was.', ['domain' => 'klant.nl']))->assertSee('ERROR 1064')
            ->assertSee(__('Check the files again'))->assertDontSee('import failed: putting');
        $this->actingAsAdmin();
        $this->get("/admin/import-wordpress/{$import->id}")->assertOk()->assertSee('import failed: putting');
        $this->assertSame(0, SiteDatabase::count());
    }

    public function test_admin_imports_into_any_website_and_tiles_link_to_the_wizard(): void
    {
        [, $user, $site] = $this->customer();
        $admin = $this->actingAsAdmin();
        $this->get('/admin/import-wordpress')->assertOk()->assertSee('klant.nl')->assertSee('Jan');
        $this->post('/admin/import-wordpress', ['website' => $site->id])->assertRedirect();
        $import = WpImport::sole();
        $this->get("/admin/import-wordpress/{$import->id}")->assertOk()->assertSee(__('Upload the two files'));
        $this->actingAs($user)->get("/websites/{$site->id}?tab=tools")->assertOk()->assertSee("/import-wordpress?website={$site->id}", false);
        $this->actingAs($user)->get("/websites/{$site->id}?tab=start")->assertOk()->assertSee(__('Move WordPress here'));
        // A second start continues the open import.
        $this->actingAs($user)->post('/import-wordpress', ['website' => $site->id])->assertRedirect("/import-wordpress/{$import->id}");
        $this->actingAs($user)->delete("/import-wordpress/{$import->id}")->assertRedirect('/import-wordpress');
        $this->assertSame(0, WpImport::count());
    }

    public function test_agent_error_on_start_is_shown(): void
    {
        [, $user, $site] = $this->customer();
        $import = $this->started($user, $site);
        $this->upload($user, $import, 'site', 'website.zip', 'PK');
        $this->upload($user, $import, 'database', 'db.zip', 'PK');
        $this->agent()->respond('wp_import.inspect', new AgentException('conflict', 'An import of this website is already running'));
        $this->actingAs($user)->post("/import-wordpress/{$import->id}/check")->assertSessionHasErrors('import');
        $this->assertSame('upload', $import->fresh()->status);
    }
}
