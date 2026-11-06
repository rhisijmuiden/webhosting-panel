<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Website;
use App\Support\ToolsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_page_lists_existing_settings_grouped(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/tools')->assertOk()
            ->assertSee('Tools &amp; settings', false)
            ->assertSee('Security')
            ->assertSee(route('admin.firewall.index'))
            ->assertSee(route('admin.settings.mail.edit'))
            ->assertSee(route('admin.system.index'));
    }

    public function test_tools_page_skips_routes_that_do_not_exist(): void
    {
        ToolsCatalog::add('mail', 'Imaginary page', 'admin.does-not-exist');
        $groups = ToolsCatalog::groups();
        ToolsCatalog::reset();
        $routes = collect($groups)->flatMap(fn ($g) => collect($g['items'])->pluck('route'));
        $this->assertNotContains('admin.does-not-exist', $routes);
        $this->assertContains('admin.firewall.index', $routes);
    }

    public function test_tools_page_is_in_dutch(): void
    {
        $this->actingAsAdmin(\App\Models\User::factory()->admin()->create(['locale' => 'nl']));
        $this->get('/admin/tools')->assertOk()->assertSee('Tools &amp; instellingen', false);
    }

    public function test_sidebar_groups_and_counts(): void
    {
        $this->actingAsAdmin();
        $package = HostingPackage::create(['name' => 'Start']);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $package->id]);
        Website::create(['customer_id' => $customer->id, 'domain' => 'example.test', 'status' => 'active']);

        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Hosting services', $html);
        $this->assertStringContainsString('Server management', $html);
        $this->assertMatchesRegularExpression('/Domains<\/span>\s*<span class="badge">1<\/span>/', $html);
        // Settings pages are reached through Tools & settings, not the sidebar.
        $this->assertStringNotContainsString('href="'.route('admin.firewall.index').'"', $html);
    }

    public function test_settings_page_highlights_tools_with_breadcrumb(): void
    {
        $this->actingAsAdmin();
        $html = $this->get('/admin/firewall')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('admin.tools.index'), '/').'" class="active"/', $html);
        $this->assertStringContainsString('<a href="'.route('admin.tools.index').'">Tools &amp; settings</a>', $html);
    }

    public function test_domain_list_search_sort_and_bulk_cache(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $a = Website::create(['customer_id' => $customer->id, 'domain' => 'alpha.test', 'status' => 'active', 'disk_used_mb' => 5]);
        Website::create(['customer_id' => $customer->id, 'domain' => 'beta.test', 'status' => 'active', 'disk_used_mb' => 50]);

        $this->get('/admin/websites?q=alpha')->assertOk()->assertSee('alpha.test')->assertDontSee('beta.test');
        $this->get('/admin/websites?q=Jan')->assertOk()->assertSee('alpha.test')->assertSee('beta.test');
        $this->get('/admin/websites?sort=disk&dir=desc')->assertOk()->assertSeeInOrder(['beta.test', 'alpha.test']);

        $this->agent()->respond('site.cache', ['ok' => true]);
        $this->post('/admin/websites/bulk', ['action' => 'cache_on', 'ids' => [$a->id]])->assertRedirect();
    }

    public function test_bulk_delete_needs_confirmation_word(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $a = Website::create(['customer_id' => $customer->id, 'domain' => 'alpha.test', 'status' => 'active']);

        $this->post('/admin/websites/bulk', ['action' => 'delete', 'ids' => [$a->id], 'confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('websites', ['id' => $a->id]);
    }

    public function test_service_plans_list_tabs_and_clone(): void
    {
        $this->actingAsAdmin();
        $plan = HostingPackage::create(['name' => 'MKB Basis', 'disk_mb' => 5000, 'crm_id' => 'P1']);
        HostingPackage::create(['name' => 'Oud', 'is_active' => false]);

        $this->get('/admin/packages')->assertOk()->assertSee('MKB Basis')->assertSee('Oud');
        $this->get('/admin/packages?tab=inactive')->assertOk()->assertDontSee('MKB Basis')->assertSee('Oud');

        $this->post("/admin/packages/{$plan->id}/duplicate")->assertRedirect();
        $copy = HostingPackage::where('name', 'MKB Basis (copy)')->firstOrFail();
        $this->assertNull($copy->crm_id);
        $this->assertFalse($copy->is_active);
        $this->assertSame(5000, (int) $copy->disk_mb);
    }
}
