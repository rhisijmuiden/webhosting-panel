<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\WpHardeningState;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WpHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function install(?Customer $customer = null, string $domain = 'harden.nl'): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.1']);
    }

    private function lastCall(string $action): ?array
    {
        return collect($this->agent()->calls)->where('action', $action)->last();
    }

    public function test_admin_sees_measures_and_card_on_the_wordpress_page(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}")->assertOk()->assertSee('Security hardening')
            ->assertSee(route('admin.wp-hardening.show', $install), false)->assertSee('Harden this site');
        $this->get("/admin/wordpress-hardening/{$install->id}")->assertOk()
            ->assertSee('Turn off the file editor in WP admin')->assertSee('Protect wp-config.php')
            ->assertSee('0/5')->assertSee('Switch everything on')->assertSee('No directory listing');
        $this->assertSame(['domain' => 'harden.nl', 'path' => ''], $this->lastCall('wphard.status')['params']);
        $this->assertSame(0, WpHardeningState::where('wordpress_install_id', $install->id)->value('on_count'));
    }

    public function test_one_measure_on_and_off_again(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->put("/admin/wordpress-hardening/{$install->id}", ['measure' => 'file_edit', 'enabled' => 1])
            ->assertRedirect(route('admin.wp-hardening.show', $install))->assertSessionHas('status');
        $this->assertSame(['file_edit' => true], $this->lastCall('wphard.apply')['params']['measures']);
        $this->assertTrue(WpHardeningState::first()->measures['file_edit']);
        $this->assertSame(1, WpHardeningState::first()->on_count);
        $this->assertTrue(ActivityLog::where('action', 'wp_hardening.applied')->exists());

        $this->put("/admin/wordpress-hardening/{$install->id}", ['measure' => 'file_edit', 'enabled' => 0])->assertSessionHas('status');
        $this->assertSame(['file_edit' => false], $this->lastCall('wphard.apply')['params']['measures']);
        $this->assertSame(0, WpHardeningState::first()->on_count);

        $this->put("/admin/wordpress-hardening/{$install->id}", ['measure' => 'rm_rf', 'enabled' => 1])->assertSessionHasErrors('measure');
    }

    public function test_switch_everything_on_and_card_shows_it(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress-hardening/{$install->id}/all")->assertSessionHas('status');
        $this->assertSame(array_fill_keys(['file_edit', 'php_folders', 'wp_config', 'hide_version', 'user_enum'], true),
            $this->lastCall('wphard.apply')['params']['measures']);
        $this->assertTrue(WpHardeningState::first()->isComplete());
        $this->get("/admin/wordpress-hardening/{$install->id}")->assertSee('This WordPress site is fully hardened')->assertDontSee('Switch everything on');
        $this->get("/admin/wordpress/{$install->id}")->assertSee('5 of 5 on')->assertSee('View measures');
    }

    public function test_salts_renew_and_undo(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress-hardening/{$install->id}/salts")->assertSessionHas('status');
        $this->assertFalse($this->lastCall('wphard.salts')['params']['undo']);
        $this->assertNotNull(WpHardeningState::first()->salts_refreshed_at);
        $this->get("/admin/wordpress-hardening/{$install->id}")->assertSee('Undo last renewal');

        $this->post("/admin/wordpress-hardening/{$install->id}/salts", ['undo' => 1])->assertSessionHas('status');
        $this->assertTrue($this->lastCall('wphard.salts')['params']['undo']);
        $this->assertTrue(ActivityLog::where('action', 'wp_hardening.salts_restored')->exists());
    }

    public function test_customer_only_own_installs_and_can_be_switched_off(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install($mine, 'mine.nl');
        $other = $this->install(null, 'other.nl');
        $user = User::factory()->create(['customer_id' => $mine->id]);
        $this->actingAs($user);

        $this->get("/wordpress/{$install->id}")->assertOk()->assertSee(route('client.wp-hardening.show', $install), false);
        $this->get("/wordpress-hardening/{$install->id}")->assertOk()->assertSee('Hide user names');
        $this->put("/wordpress-hardening/{$install->id}", ['measure' => 'user_enum', 'enabled' => 1])->assertRedirect(route('client.wp-hardening.show', $install));
        $this->assertSame(['domain' => 'mine.nl', 'path' => '', 'measures' => ['user_enum' => true]], $this->lastCall('wphard.apply')['params']);

        $this->get("/wordpress-hardening/{$other->id}")->assertNotFound();
        $this->post("/wordpress-hardening/{$other->id}/all")->assertNotFound();

        config(['wp-hardening.customer_access' => false]);
        $this->get("/wordpress-hardening/{$install->id}")->assertNotFound();
        $this->get("/wordpress/{$install->id}")->assertOk()->assertDontSee(route('client.wp-hardening.show', $install), false);
    }

    public function test_dutch_page(): void
    {
        $install = $this->install();
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['locale' => 'nl'])->save();

        $this->get("/admin/wordpress-hardening/{$install->id}")->assertOk()
            ->assertSee('Beveiliging versterken')->assertSee('Bestandseditor in WP-admin uitzetten')->assertSee('Alles aanzetten');
    }

    public function test_agent_errors_are_shown(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->agent()->respond('wphard.status', AgentException::unreachable('Agent down'));
        $this->get("/admin/wordpress-hardening/{$install->id}")->assertOk()->assertSee('Agent down');

        $this->agent()->respond('wphard.apply', new AgentException('conflict', 'wp-config.php has an unusual layout'));
        $this->put("/admin/wordpress-hardening/{$install->id}", ['measure' => 'file_edit', 'enabled' => 1])->assertSessionHasErrors('agent');
        $this->assertFalse(ActivityLog::where('action', 'wp_hardening.applied')->exists());
    }
}
