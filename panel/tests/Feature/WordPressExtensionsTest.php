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

class WordPressExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);
        $this->artisan('whp:wordpress:scan')->assertSuccessful();

        return WordPressInstall::where('website_id', $site->id)->firstOrFail();
    }

    private function base(WordPressInstall $i, string $area = 'admin'): string
    {
        return ($area === 'admin' ? '/admin' : '')."/wordpress/{$i->id}/extensions";
    }

    private function lastCall(string $action): array
    {
        return collect($this->agent()->calls)->where('action', $action)->last()['params'];
    }

    public function test_admin_sees_plugins_themes_and_the_links_to_the_page(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();

        $this->get($this->base($i))->assertOk()
            ->assertSee('WooCommerce')->assertSee('Hello Dolly')->assertSee('9.4.1 available')
            ->assertSee('role="switch"', false)->assertSee('value="deactivate|woocommerce"', false)
            ->assertSee('value="delete|hello-dolly"', false)->assertDontSee('value="delete|woocommerce"', false);
        $this->get($this->base($i).'?tab=themes')->assertOk()->assertSee('Astra')->assertSee('Twenty Twenty-Four')
            ->assertSee('value="activate|twentytwentyfour"', false)->assertDontSee('value="delete|astra"', false);
        $this->get("/admin/wordpress/{$i->id}")->assertSee(route('admin.wordpress.extensions.index', $i), false);
    }

    public function test_row_buttons_and_bulk_actions_reach_the_agent(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();

        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'row' => 'activate|hello-dolly'])
            ->assertRedirect($this->base($i).'?tab=plugins')->assertSessionHas('status', 'hello-dolly activated.');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'kind' => 'plugin', 'op' => 'activate', 'slugs' => ['hello-dolly']], $this->lastCall('wp.ext_apply'));
        $this->assertSame('active', collect($i->fresh()->plugins)->firstWhere('name', 'hello-dolly')['status']);

        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'op' => 'auto_update_on', 'slugs' => ['woocommerce', 'contact-form-7']])
            ->assertSessionHas('status', 'Automatic updates switched on for woocommerce, contact-form-7.');
        $this->assertTrue(collect($i->fresh()->plugins)->firstWhere('name', 'woocommerce')['auto_update']);
        $this->assertSame(2, ActivityLog::where('action', 'like', 'wordpress.plugin.%')->count());

        // Delete refuses an active plugin; the reason is shown.
        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'row' => 'delete|woocommerce'])->assertSessionHasErrors('items');
        $this->post($this->base($i).'/apply', ['kind' => 'theme', 'row' => 'activate|twentytwentyfour'])->assertSessionHas('status');
        $this->assertSame('active', collect($i->fresh()->themes)->firstWhere('name', 'twentytwentyfour')['status']);
    }

    public function test_invalid_requests_are_refused(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();

        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'op' => 'activate', 'slugs' => ['../evil']])->assertSessionHasErrors('slugs.0');
        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'op' => 'activate'])->assertSessionHasErrors('slugs');
        $this->post($this->base($i).'/apply', ['kind' => 'theme', 'row' => 'deactivate|astra'])->assertSessionHasErrors('agent');
        $this->post($this->base($i).'/apply', ['kind' => 'theme', 'op' => 'activate', 'slugs' => ['astra', 'twentytwentyfour']])->assertSessionHasErrors('agent');
        $this->post($this->base($i).'/install', ['kind' => 'plugin', 'slug' => 'https://evil.test/x.zip'])->assertSessionHasErrors('slug');
        $this->assertFalse(collect($this->agent()->calls)->contains(fn ($c) => in_array($c['action'], ['wp.ext_apply', 'wp.ext_install'], true)));
    }

    public function test_rollback_after_a_broken_activation_is_explained(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.ext_apply', fn ($p) => ['done' => [], 'rolled_back' => true, 'health' => ['before' => 200, 'after' => 500, 'after_rollback' => 200],
            'errors' => [['slug' => 'hello-dolly', 'error' => 'The website answered HTTP 500 after activating hello-dolly, so it was undone']], 'plugins' => $i->plugins, 'themes' => $i->themes]);

        $this->post($this->base($i).'/apply', ['kind' => 'plugin', 'row' => 'activate|hello-dolly'])
            ->assertSessionHas('wp_rolled_back')->assertSessionHasErrors('items');
        $this->get($this->base($i))->assertSee('Your website stopped working, so we undid the change')->assertSee('HTTP 500');
    }

    public function test_search_and_install_from_wordpress_org(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();

        $this->get($this->base($i).'?tab=add')->assertOk()->assertSee('Search WordPress.org plugins')->assertSee('Contact form');
        $this->get($this->base($i).'?tab=add&kind=plugin&q=seo')->assertOk()
            ->assertSee('Yoast SEO')->assertSee('Rank Math SEO')->assertSee('value="wordpress-seo"', false)->assertSee('10 million+ active installations');
        // Searches are cached: the same search again does not ask the agent.
        $this->get($this->base($i).'?tab=add&kind=plugin&q=SEO');
        $this->assertSame(1, collect($this->agent()->calls)->where('action', 'wp.ext_search')->count());
        // An installed plugin is marked as such.
        $this->get($this->base($i).'?tab=add&kind=plugin&q=contact')->assertSee('Installed')->assertDontSee('value="contact-form-7"', false);

        $this->post($this->base($i).'/install', ['kind' => 'plugin', 'slug' => 'wordpress-seo', 'activate' => '1', 'q' => 'seo'])
            ->assertRedirect($this->base($i).'?tab=plugins')->assertSessionHas('status', 'wordpress-seo installed and activated.')->assertSessionHas('highlight', 'wordpress-seo');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'kind' => 'plugin', 'slug' => 'wordpress-seo', 'activate' => true], $this->lastCall('wp.ext_install'));
        $this->assertSame('active', collect($i->fresh()->plugins)->firstWhere('name', 'wordpress-seo')['status']);

        // Installing it again fails with the agent's reason.
        $this->post($this->base($i).'/install', ['kind' => 'plugin', 'slug' => 'wordpress-seo', 'q' => 'seo'])
            ->assertRedirect($this->base($i).'?tab=add&kind=plugin&q=seo')->assertSessionHasErrors('agent');
    }

    public function test_agent_failure_on_the_list_shows_the_last_known_list(): void
    {
        $i = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.ext_list', new AgentException('command_failed', 'WordPress (wp plugin) failed: Error establishing a database connection'));

        $this->get($this->base($i))->assertOk()->assertSee('Error establishing a database connection')->assertSee('WooCommerce');
    }

    public function test_customers_manage_only_their_own_installs_and_can_be_switched_off(): void
    {
        $mine = Customer::create(['name' => 'Mijn', 'email' => 'mijn@a.nl']);
        $own = $this->install('mijn.nl', $mine);
        $other = $this->install('ander.nl');
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->get($this->base($own, 'client'))->assertOk()->assertSee('WooCommerce');
        $this->get("/wordpress/{$own->id}")->assertSee(route('client.wordpress.extensions.index', $own), false);
        $this->post($this->base($own, 'client').'/apply', ['kind' => 'plugin', 'row' => 'deactivate|wordfence'])->assertSessionHas('status');
        $this->assertSame($mine->id, ActivityLog::where('action', 'wordpress.plugin.deactivate')->first()->customer_id);

        $this->get($this->base($other, 'client'))->assertNotFound();
        $this->post($this->base($other, 'client').'/apply', ['kind' => 'plugin', 'row' => 'deactivate|wordfence'])->assertNotFound();
        $this->post($this->base($other, 'client').'/install', ['kind' => 'plugin', 'slug' => 'wordpress-seo'])->assertNotFound();

        config(['wordpress-extensions.customer_access' => false]);
        $this->get($this->base($own, 'client'))->assertNotFound();
        $this->get("/wordpress/{$own->id}")->assertOk()->assertDontSee(route('client.wordpress.extensions.index', $own), false);
    }

    public function test_dutch_page(): void
    {
        $i = $this->install();
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));

        $this->get($this->base($i))->assertOk()->assertSee('Plugins &amp; thema&#039;s', false)->assertSee('Deactiveren')->assertSee('Automatische updates');
        $this->get($this->base($i).'?tab=add&q=seo')->assertSee('Installeren &amp; activeren', false)->assertSee('Populair:');
    }
}
