<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WordPressTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain = 'klant.nl', ?Customer $customer = null): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);
    }

    private function scanned(?Website $site = null): WordPressInstall
    {
        $site ??= $this->site();
        $this->artisan('whp:wordpress:scan')->assertSuccessful();

        return WordPressInstall::where('website_id', $site->id)->firstOrFail();
    }

    private function jobId(string $location): string
    {
        $this->assertMatchesRegularExpression('#/wordpress/jobs/[0-9a-f-]{36}$#', $location);

        return substr($location, -36);
    }

    public function test_scan_finds_installs_and_stores_outdated_parts(): void
    {
        $install = $this->scanned();

        $this->assertSame('6.6.2', $install->version);
        $this->assertSame('6.7.1', $install->core_update);
        $this->assertSame(2, $install->outdated_plugins);
        $this->assertSame(1, $install->outdated_themes);
        $this->assertNotNull($install->checked_at);

        // An install that is gone is removed at the next scan.
        $this->agent()->respond('wp.detect', ['installs' => []]);
        $this->artisan('whp:wordpress:scan')->assertSuccessful();
        $this->assertSame(0, WordPressInstall::count());
    }

    public function test_admin_overview_security_card_and_detail_page(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();

        $this->get('/admin/wordpress')->assertOk()->assertSee('klant.nl')->assertSee('6.7.1')
            ->assertSee(route('admin.wordpress.update-outdated'), false);
        $this->get('/admin/wordpress?filter=outdated')->assertOk()->assertSee('klant.nl');
        $this->get('/admin/security')->assertOk()->assertSee('Open WordPress toolkit')->assertSee('1 of 1 sites outdated');
        $this->get('/admin')->assertSee(route('admin.wordpress.index'), false);
        $this->get("/admin/wordpress/{$install->id}")->assertOk()
            ->assertSee('WooCommerce')->assertSee('9.4.1')->assertSee('beheerder')->assertSee('WP-cron');
        $this->get("/admin/websites/{$install->website_id}")->assertOk()->assertSee(route('admin.wordpress.show', $install), false);
    }

    public function test_update_everything_starts_a_job_with_snapshot_and_rollback(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();

        $res = $this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'all']);
        $res->assertRedirect();
        $this->jobId($res->headers->get('Location'));
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.update');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'core' => true, 'plugins' => 'all', 'themes' => 'all',
            'translations' => true, 'snapshot' => true, 'rollback' => true], $call['params']);
        $this->assertTrue(ActivityLog::where('action', 'wordpress.update')->exists());
    }

    public function test_update_selected_validates_slugs(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'selected', 'plugins' => ['woocommerce'], 'snapshot' => '0'])->assertRedirect();
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.update');
        $this->assertSame(['woocommerce'], $call['params']['plugins']);
        $this->assertFalse($call['params']['themes']);
        $this->assertFalse($call['params']['core']);
        $this->assertFalse($call['params']['snapshot']);

        $this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'selected', 'plugins' => ['../../etc']])->assertSessionHasErrors('plugins.0');
        $this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'selected'])->assertSessionHasErrors('agent');
    }

    public function test_finished_job_is_stored_on_the_install(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();
        $job = $this->jobId($this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'all'])->headers->get('Location'));

        $this->agent()->respond('job.status', ['id' => $job, 'status' => 'running', 'log' => ['Updating plugins'], 'result' => null, 'error' => null]);
        $this->get("/admin/wordpress/jobs/{$job}")->assertOk()->assertSee('http-equiv="refresh"', false)->assertSee('Updating plugins');

        $this->agent()->respond('job.status', ['id' => $job, 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'domain' => 'klant.nl', 'path' => '', 'rolled_back' => false, 'errors' => [], 'health' => ['before' => 200, 'after' => 200],
            'updated' => ['core' => ['old_version' => '6.6.2', 'new_version' => '6.7.1'], 'plugins' => [['name' => 'woocommerce', 'old_version' => '9.1.2', 'new_version' => '9.4.1']], 'themes' => []],
            'status' => ['version' => '6.7.1', 'core_update' => null, 'plugins' => [], 'themes' => [], 'outdated' => ['plugins' => 0, 'themes' => 0], 'cron' => ['mode' => 'wordpress']],
        ]]);
        $this->get("/admin/wordpress/jobs/{$job}")->assertOk()->assertSee('woocommerce 9.1.2 → 9.4.1');
        $install->refresh();
        $this->assertSame('succeeded', $install->last_update_status);
        $this->assertSame('6.7.1', $install->version);
        $this->assertFalse($install->isOutdated());
    }

    public function test_rolled_back_update_is_reported(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();
        $job = $this->jobId($this->post("/admin/wordpress/{$install->id}/update", ['scope' => 'all'])->headers->get('Location'));
        $this->agent()->respond('job.status', ['id' => $job, 'status' => 'failed', 'log' => [], 'result' => null, 'error' => [
            'code' => 'command_failed', 'message' => 'The site broke after the update and was rolled back',
            'details' => ['rolled_back' => true, 'health' => ['before' => 200, 'after' => 500], 'updated' => ['plugins' => [], 'themes' => []], 'errors' => []],
        ]]);

        $this->get("/admin/wordpress/jobs/{$job}")->assertOk()->assertSee('Rolled back')->assertSee('HTTP 500');
        $this->assertSame('rolled_back', $install->fresh()->last_update_status);
    }

    public function test_update_all_outdated_is_one_bulk_job(): void
    {
        $a = $this->scanned();
        $this->site('tweede.nl');
        $this->artisan('whp:wordpress:scan');
        $this->actingAsAdmin();

        $this->post('/admin/wordpress/update-outdated')->assertRedirect();
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.update_many');
        $this->assertSame(['klant.nl', 'tweede.nl'], collect($call['params']['installs'])->pluck('domain')->sort()->values()->all());
        $this->assertTrue($call['params']['rollback']);

        $this->artisan('whp:wordpress:collect')->assertSuccessful();
        $this->assertSame('failed', $a->fresh()->last_update_status); // the fake job has no results
    }

    public function test_cron_takeover(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();

        $this->put("/admin/wordpress/{$install->id}/cron", ['mode' => 'system', 'interval' => 15])->assertRedirect()->assertSessionHas('status');
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.cron_set');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'mode' => 'system', 'interval' => 15], $call['params']);
        $this->assertSame('system', $install->fresh()->cron_mode);
        $this->put("/admin/wordpress/{$install->id}/cron", ['mode' => 'system', 'interval' => 2])->assertSessionHasErrors('interval');
    }

    public function test_client_can_change_password_and_log_in_but_only_on_own_sites(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->scanned($this->site('mine.nl', $mine));
        $other = $this->site('other.nl');
        $this->artisan('whp:wordpress:scan');
        $otherInstall = WordPressInstall::where('website_id', $other->id)->first();
        $user = User::factory()->create(['customer_id' => $mine->id]);
        $this->actingAs($user);

        $this->get('/wordpress')->assertOk()->assertSee('mine.nl')->assertDontSee('other.nl');
        $this->get("/wordpress/{$install->id}")->assertOk()->assertSee('Log in to WP admin');
        $this->get("/websites/{$install->website_id}")->assertOk()->assertSee(route('client.wordpress.show', $install), false);
        $this->get("/wordpress/{$otherInstall->id}")->assertNotFound();
        $this->post("/wordpress/{$otherInstall->id}/login")->assertNotFound();
        $this->post("/wordpress/{$otherInstall->id}/password", ['user_id' => 1, 'mode' => 'generate'])->assertNotFound();

        // One-click login: straight to the single use link.
        $res = $this->post("/wordpress/{$install->id}/login");
        $res->assertRedirect();
        $this->assertStringStartsWith('https://mine.nl/wp-login.php?whp_sso=', $res->headers->get('Location'));

        // Generated password is shown once.
        $this->post("/wordpress/{$install->id}/password", ['user_id' => 1, 'mode' => 'generate'])
            ->assertSessionHas('wp_password', ['user' => 'beheerder', 'password' => 'Fake-Generated-Pass-2026']);
        // Own password: confirmed and at least 12 characters; never written to the activity log.
        $this->post("/wordpress/{$install->id}/password", ['user_id' => 1, 'mode' => 'set', 'password' => 'kort', 'password_confirmation' => 'kort'])
            ->assertSessionHasErrors('password');
        $this->post("/wordpress/{$install->id}/password", ['user_id' => 1, 'mode' => 'set', 'password' => 'Lang-Genoeg-2026!', 'password_confirmation' => 'Lang-Genoeg-2026!'])
            ->assertSessionHas('status')->assertSessionMissing('wp_password');
        $call = collect($this->agent()->calls)->where('action', 'wp.set_password')->last();
        $this->assertSame('Lang-Genoeg-2026!', $call['params']['password']);
        $this->assertStringNotContainsString('Lang-Genoeg', ActivityLog::all()->toJson());

        // Customers always get a snapshot, even when they ask to skip it.
        $this->post("/wordpress/{$install->id}/update", ['scope' => 'all', 'snapshot' => '0'])->assertRedirect();
        $this->assertTrue(collect($this->agent()->calls)->firstWhere('action', 'wp.update')['params']['snapshot']);

        // Customers cannot follow someone else's job.
        $this->post("/wordpress/{$install->id}/update", ['scope' => 'all']);
        $this->actingAs(User::factory()->create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@x.test'])->id]));
        $job = collect(\Illuminate\Support\Facades\Cache::get('wordpress.jobs'))->first()['id'];
        $this->get("/wordpress/jobs/{$job}")->assertNotFound();
    }

    public function test_client_menu_only_with_wordpress_and_toolkit_can_be_switched_off(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $user = User::factory()->create(['customer_id' => $mine->id]);
        $this->actingAs($user)->get('/dashboard')->assertDontSee(route('client.wordpress.index'), false);

        $install = $this->scanned($this->site('mine.nl', $mine));
        $this->actingAs($user)->get('/dashboard')->assertSee(route('client.wordpress.index'), false);

        config(['wordpress.customer_toolkit' => false]);
        $this->actingAs($user)->get("/wordpress/{$install->id}")->assertNotFound();
        $this->actingAs($user)->get('/wordpress')->assertNotFound();
    }

    public function test_pages_survive_an_unreachable_agent(): void
    {
        $install = $this->scanned();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.users', AgentException::unreachable('Agent down'));

        $this->get("/admin/wordpress/{$install->id}")->assertOk()->assertSee('Agent down');
        $this->agent()->respond('wp.login_link', AgentException::unreachable('Agent down'));
        $this->post("/admin/wordpress/{$install->id}/login")->assertSessionHasErrors('agent');
    }
}
