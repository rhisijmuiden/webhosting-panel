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

class SiteOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $package = []): array
    {
        $plan = HostingPackage::create(array_merge(['name' => 'Webhosting M', 'disk_mb' => 5000, 'max_websites' => 3], $package));
        $customer = Customer::create(['name' => 'Arie', 'company' => 'Arie & Zn.', 'email' => 'arie@example.test']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => 'arie.nl', 'status' => 'active', 'php_version' => '8.4', 'ssl_status' => 'active',
            'system_user' => 'arie', 'document_root' => '/var/www/vhosts/arie.nl/public_html', 'disk_used_mb' => 680]);
        $site = $this->withPackage($site, $plan);

        return [$customer, $user, $site];
    }

    public function test_domain_page_shows_tools_package_and_quick_links(): void
    {
        [, $user, $site] = $this->site();

        $this->actingAs($user)->get("/websites/{$site->id}")->assertOk()
            ->assertSee('Files &amp; data', false)->assertSee('Developer tools')->assertSee('File manager')
            ->assertSee('Quick links')->assertSee('Subscription')->assertSee('Webhosting M')->assertSee('Arie &amp; Zn.', false)
            ->assertSee('public_html')->assertSee('System user')
            ->assertDontSee('Log in to WordPress');
    }

    public function test_every_tab_renders_and_unknown_tabs_fall_back(): void
    {
        [, $user, $site] = $this->site();
        $this->actingAs($user);

        foreach (['tools', 'hosting', 'mail', 'start'] as $tab) {
            $this->get("/websites/{$site->id}?tab={$tab}")->assertOk();
        }
        $this->get("/websites/{$site->id}?tab=hosting")->assertSee('PHP version')->assertSee('Connection details')->assertSee('Domain aliases');
        $this->get("/websites/{$site->id}?tab=start")->assertSee('Upload files')->assertSee('Publish with Git');
        $this->get("/websites/{$site->id}?tab=wordpress")->assertOk()->assertSee('Developer tools');
        $this->get("/websites/{$site->id}?tab[]=x")->assertOk()->assertSee('Developer tools');
    }

    public function test_wordpress_tab_is_first_when_the_site_runs_wordpress(): void
    {
        [, $user, $site] = $this->site();
        WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.1', 'core_update' => '6.8.2', 'outdated_plugins' => 2, 'outdated_themes' => 0]);

        $this->actingAs($user)->get("/websites/{$site->id}")->assertOk()
            ->assertSee('Log in to WordPress')->assertSee('6.8.2')->assertSee('2 updates')->assertSee('Update everything');
    }

    public function test_mail_tab_links_to_the_mail_domain(): void
    {
        [, $user, $site] = $this->site();
        $domain = MailDomain::where('domain', 'arie.nl')->first()
            ?? MailDomain::create(['customer_id' => $site->customer_id, 'website_id' => $site->id, 'domain' => 'arie.nl']);

        $this->actingAs($user)->get("/websites/{$site->id}?tab=mail")->assertOk()
            ->assertSee(route('client.mail.domains.show', [$domain->id, 'tab' => 'mailboxes']), false)->assertSee('Mail settings');
    }

    public function test_web_terminal_only_with_ssh_in_the_package(): void
    {
        [$customer, $user, $site] = $this->site();
        $this->actingAs($user)->get("/websites/{$site->id}")->assertDontSee('Web terminal');

        $site->package->update(['allow_ssh' => true]);
        $this->actingAs($user)->get("/websites/{$site->id}")->assertSee('Web terminal');
    }

    public function test_dutch_translation(): void
    {
        [, $user, $site] = $this->site();
        $user->forceFill(['locale' => 'nl'])->save();

        $this->actingAs($user)->withSession(['locale' => 'nl'])->get("/websites/{$site->id}")->assertOk()
            ->assertSee('Bestanden &amp; data', false)->assertSee('Snel naar')->assertSee('Aan de slag');
    }

    public function test_other_customers_site_is_not_found(): void
    {
        [, , $site] = $this->site();
        $other = Customer::create(['name' => 'Other', 'email' => 'o@example.test']);

        $this->actingAs(User::factory()->create(['customer_id' => $other->id]))->get("/websites/{$site->id}?tab=hosting")->assertNotFound();
    }
}
