<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Switches\SwitchState;
use Tests\TestCase;

class WordPressSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.7.1', 'checked_at' => now()]);
    }

    private function lastCall(string $action): ?array
    {
        return collect($this->agent()->calls)->where('action', $action)->last();
    }

    public function test_install_page_shows_the_switches_read_fresh_from_wordpress(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}")->assertOk()
            ->assertSee('Quick switches')->assertSee('Maintenance mode')->assertSee('Debug mode')
            ->assertSee('Search engines')->assertSee('Clear cache')
            ->assertSee(route('admin.wordpress.switches.maintenance', $install), false);
        $this->assertNotNull($this->lastCall('wp.switches'));
        $this->assertTrue(SwitchState::where('wordpress_install_id', $install->id)->exists());
    }

    public function test_maintenance_mode_on_and_off(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->put("/admin/wordpress/{$install->id}/switches/maintenance", ['enabled' => 1, 'title' => 'Even geduld', 'message' => "Regel 1\nRegel 2"])
            ->assertRedirect()->assertSessionHas('status');
        $call = $this->lastCall('wp.maintenance_set');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'enabled' => true, 'title' => 'Even geduld', 'message' => "Regel 1\nRegel 2", 'lang' => 'en'], $call['params']);
        $state = SwitchState::firstWhere('wordpress_install_id', $install->id);
        $this->assertTrue($state->maintenance);
        $this->assertSame('Even geduld', $state->maintenance_title);
        $this->assertTrue(ActivityLog::where('action', 'wordpress.maintenance_on')->exists());

        $this->get("/admin/wordpress/{$install->id}")->assertSee('On for');

        $this->put("/admin/wordpress/{$install->id}/switches/maintenance", ['enabled' => 0])->assertSessionHas('status');
        $this->assertFalse($state->fresh()->maintenance);
        $this->assertSame('Even geduld', $state->fresh()->maintenance_title, 'the last text is kept for next time');
    }

    public function test_maintenance_without_text_gets_a_default_in_the_users_language(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        app()->setLocale('nl');
        $this->withSession(['locale' => 'nl', 'two_factor_passed' => true]);

        $this->put("/admin/wordpress/{$install->id}/switches/maintenance", ['enabled' => 1, 'title' => '', 'message' => ''])->assertRedirect();
        $params = $this->lastCall('wp.maintenance_set')['params'];
        $this->assertNotSame('', $params['title']);
        $this->assertNotSame('', $params['message']);
        $this->assertContains($params['lang'], ['en', 'nl']);
    }

    public function test_debug_mode_switches_itself_off_when_its_time_is_up(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->put("/admin/wordpress/{$install->id}/switches/debug", ['enabled' => 1, 'hours' => 4])->assertSessionHas('status');
        $state = SwitchState::firstWhere('wordpress_install_id', $install->id);
        $this->assertTrue($state->debug);
        $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, $state->debug_until->timestamp, 5);

        $this->artisan('whp:wordpress:debug-expire')->assertSuccessful();
        $this->assertTrue($state->fresh()->debug, 'not yet');

        $this->travel(5)->hours();
        $this->artisan('whp:wordpress:debug-expire')->assertSuccessful();
        $this->assertFalse($state->fresh()->debug);
        $this->assertNull($state->fresh()->debug_until);
        $this->assertFalse($this->lastCall('wp.debug_set')['params']['enabled']);
        $this->assertTrue(ActivityLog::where('action', 'wordpress.debug_expired')->exists());
    }

    public function test_only_admins_may_leave_debug_mode_on_forever(): void
    {
        $customer = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mine.nl', $customer);
        $this->actingAs(User::factory()->create(['customer_id' => $customer->id]));

        $this->put("/wordpress/{$install->id}/switches/debug", ['enabled' => 1, 'hours' => 0])->assertSessionHasErrors('hours');
        $this->put("/wordpress/{$install->id}/switches/debug", ['enabled' => 1, 'hours' => 2])->assertSessionHasErrors('hours');
        $this->put("/wordpress/{$install->id}/switches/debug", ['enabled' => 1])->assertSessionHas('status');
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, SwitchState::first()->debug_until->timestamp, 5);

        $this->actingAsAdmin();
        $this->put("/admin/wordpress/{$install->id}/switches/debug", ['enabled' => 1, 'hours' => 0])->assertSessionHas('status');
        $this->assertNull(SwitchState::first()->debug_until);
    }

    public function test_indexing_and_clear_cache(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->put("/admin/wordpress/{$install->id}/switches/indexing", ['enabled' => 0])->assertSessionHas('status');
        $this->assertFalse(SwitchState::first()->indexing);
        $this->get("/admin/wordpress/{$install->id}")->assertSee('Hidden from search engines');

        $this->post("/admin/wordpress/{$install->id}/switches/cache")
            ->assertSessionHas('status', 'All caches are empty, including WP Rocket, Elementor CSS.');
        $this->assertNotNull(SwitchState::first()->cache_cleared_at);
    }

    public function test_a_failing_cache_step_is_reported(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.cache_clear', ['ok' => false, 'steps' => [
            ['step' => 'page_cache', 'ok' => true], ['step' => 'object_cache', 'ok' => true],
            ['step' => 'plugins', 'ok' => false, 'plugins' => [], 'error' => 'Fatal'], ['step' => 'transients', 'ok' => true],
        ]]);
        $this->post("/admin/wordpress/{$install->id}/switches/cache")->assertSessionHasErrors('agent');
    }

    public function test_agent_errors_go_back_with_a_message(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.indexing_set', new AgentException('command_failed', 'WordPress (wp option) failed'));
        $this->put("/admin/wordpress/{$install->id}/switches/indexing", ['enabled' => 0])->assertSessionHasErrors('agent');
    }

    public function test_debug_log_viewer_filters_and_live_entries(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}/debug-log")->assertOk()
            ->assertSee('Debug log')->assertSee('Call to undefined function shop_price()')->assertSee('Undefined array key');
        $this->get("/admin/wordpress/{$install->id}/debug-log?level=error")->assertOk()
            ->assertSee('shop_price')->assertDontSee('Undefined array key');
        $this->assertSame('error', $this->lastCall('wp.debug_log')['params']['level']);
        $this->get("/admin/wordpress/{$install->id}/debug-log?q=textdomain")->assertOk()->assertDontSee('shop_price');
        $this->get("/admin/wordpress/{$install->id}/debug-log?level=bogus")->assertSessionHasErrors('level');

        $json = $this->getJson("/admin/wordpress/{$install->id}/debug-log/entries?level=warning")->assertOk()->json();
        $this->assertStringContainsString('Undefined array key', $json['html']);
        $this->assertStringNotContainsString('shop_price', $json['html']);

        $this->post("/admin/wordpress/{$install->id}/debug-log/clear")->assertSessionHas('status');
        $this->assertSame('panel', $this->lastCall('wp.debug_log_clear')['params']['which']);
        $this->post("/admin/wordpress/{$install->id}/debug-log/clear", ['which' => 'public'])->assertSessionHas('status');
        $this->assertSame('public', $this->lastCall('wp.debug_log_clear')['params']['which']);
    }

    public function test_a_public_debug_log_is_flagged(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.switches', ['maintenance' => false, 'debug' => true, 'debug_log' => 'public', 'indexing' => true,
            'log' => ['size' => 0], 'public_log' => ['size' => 2_400_000, 'modified' => now()->timestamp]]);

        $this->get("/admin/wordpress/{$install->id}")->assertOk()
            ->assertSee('There is a public debug.log')->assertSee('2.3 MB');
    }

    public function test_customers_see_switches_on_their_domain_page_and_only_for_their_own_sites(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mine.nl', $mine);
        $other = $this->install('other.nl');
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->get("/websites/{$install->website_id}")->assertOk()
            ->assertSee('Quick switches')->assertSee(route('client.wordpress.switches.cache', $install), false);
        $this->get("/wordpress/{$install->id}/debug-log")->assertOk();
        $this->post("/wordpress/{$install->id}/switches/cache")->assertSessionHas('status');

        $this->get("/wordpress/{$other->id}/debug-log")->assertNotFound();
        $this->put("/wordpress/{$other->id}/switches/maintenance", ['enabled' => 1])->assertNotFound();
        $this->post("/wordpress/{$other->id}/switches/cache")->assertNotFound();
        $this->post("/wordpress/{$other->id}/debug-log/clear")->assertNotFound();
    }

    public function test_switches_are_off_for_customers_when_the_toolkit_is_off(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mine.nl', $mine);
        config(['wordpress.customer_toolkit' => false]);
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->post("/wordpress/{$install->id}/switches/cache")->assertNotFound();
        $this->get("/wordpress/{$install->id}/debug-log")->assertNotFound();
    }

    public function test_admin_domain_page_shows_the_switches_too(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get(route('admin.websites.show', [$install->website_id, 'tab' => 'wordpress']))->assertOk()
            ->assertSee(route('admin.wordpress.switches.cache', $install), false);
    }
}
