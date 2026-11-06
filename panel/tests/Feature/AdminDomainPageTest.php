<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

/** The admin opens a domain and gets the same domain page as the customer, with admin routes. */
class AdminDomainPageTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        $plan = HostingPackage::create(['name' => 'Webhosting M', 'disk_mb' => 5000, 'max_websites' => 3]);
        $customer = Customer::create(['name' => 'Arie', 'company' => 'Arie & Zn.', 'email' => 'arie@example.test']);
        $site = $customer->websites()->create(['domain' => 'arie.nl', 'status' => 'active', 'php_version' => '8.4', 'ssl_status' => 'active',
            'system_user' => 'arie', 'document_root' => '/var/www/vhosts/arie.nl/public_html', 'disk_used_mb' => 680]);

        return $this->withPackage($site, $plan);
    }

    public function test_admin_sees_the_client_domain_page_with_admin_links(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();

        $this->get("/admin/websites/{$site->id}")->assertOk()
            ->assertSee('Developer tools')->assertSee('Quick links')->assertSee('Webhosting M')
            ->assertSee('Administration')
            ->assertSee(route('admin.files.browse', $site), false)
            ->assertSee(route('admin.websites.show', [$site, 'tab' => 'hosting']), false)
            ->assertSee(route('admin.customers.show', $site->customer), false)
            ->assertSee(route('admin.subscriptions.edit', $site->subscription), false)
            ->assertDontSee(route('client.files.browse', $site), false)
            ->assertDontSee(route('client.websites.show', $site), false)
            ->assertDontSee('More space? Upgrade your package');
    }

    public function test_every_tab_renders_for_admins(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();

        foreach (['tools', 'hosting', 'mail', 'start', 'admin'] as $tab) {
            $this->get("/admin/websites/{$site->id}?tab={$tab}")->assertOk();
        }
        $this->get("/admin/websites/{$site->id}?tab=hosting")->assertSee('PHP version')
            ->assertSee(route('admin.websites.update', $site), false)
            ->assertSee(route('admin.websites.php', $site), false)
            ->assertDontSee(route('client.websites.update', $site), false);
        $this->get("/admin/websites/{$site->id}?tab=admin")->assertSee('Remove website')->assertSee('whp-sites-')
            ->assertSee(route('admin.websites.destroy', $site), false);
        // Admin-only tools are not shown to the customer, and the client page has no admin tab.
        $this->actingAs(User::factory()->create(['customer_id' => $site->customer_id]))
            ->get("/websites/{$site->id}?tab=admin")->assertOk()->assertDontSee('Remove website')->assertDontSee('Administration');
    }

    public function test_admin_wordpress_and_mail_links_use_admin_routes(): void
    {
        $site = $this->site();
        $install = WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.1', 'core_update' => '6.8.2', 'outdated_plugins' => 1]);
        $domain = MailDomain::where('domain', 'arie.nl')->first()
            ?? MailDomain::create(['customer_id' => $site->customer_id, 'website_id' => $site->id, 'domain' => 'arie.nl']);
        $this->actingAsAdmin();

        $this->get("/admin/websites/{$site->id}")->assertOk()
            ->assertSee('Log in to WordPress')
            ->assertSee(route('admin.wordpress.login', $install), false)
            ->assertSee(route('admin.wordpress.update', $install), false)
            ->assertDontSee(route('client.wordpress.show', $install), false);
        $this->get("/admin/websites/{$site->id}?tab=mail")->assertOk()
            ->assertSee(route('admin.mail.domains.show', [$domain->id, 'tab' => 'mail']), false);
    }

    public function test_admin_can_save_from_the_domain_page(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();

        $this->from("/admin/websites/{$site->id}?tab=hosting")
            ->put("/admin/websites/{$site->id}", ['page_cache' => '0'])
            ->assertRedirect("/admin/websites/{$site->id}?tab=hosting");
    }

    public function test_failed_setup_and_certificate_button_are_shown_to_admins_only(): void
    {
        $site = $this->site();
        $site->update(['status' => 'failed', 'ssl_status' => 'pending', 'last_error' => 'whp-site exited with code 1']);
        $this->actingAsAdmin();

        $this->get("/admin/websites/{$site->id}")->assertOk()
            ->assertSee('whp-site exited with code 1')->assertSee(route('admin.websites.ssl', $site), false);
        $this->actingAs(User::factory()->create(['customer_id' => $site->customer_id]))
            ->get("/websites/{$site->id}")->assertOk()->assertDontSee('whp-site exited with code 1');
    }

    public function test_customers_cannot_open_the_admin_domain_page(): void
    {
        $site = $this->site();

        $this->actingAs(User::factory()->create(['customer_id' => $site->customer_id]))
            ->get("/admin/websites/{$site->id}")->assertForbidden();
    }

    public function test_dutch_admin_domain_page(): void
    {
        $site = $this->site();
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['locale' => 'nl'])->save();

        $this->withSession(['locale' => 'nl', 'two_factor_passed' => true])->get("/admin/websites/{$site->id}?tab=admin")->assertOk()
            ->assertSee('Beheer')->assertSee('Website verwijderen')->assertSee('Domeinen');
    }
}
