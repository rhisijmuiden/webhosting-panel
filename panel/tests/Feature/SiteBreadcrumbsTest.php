<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

/** The top bar shows the domain you are working on, with every step back as a link. */
class SiteBreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        $customer = Customer::create(['name' => 'Reinder', 'email' => 'reinder@example.test']);

        return Website::create(['customer_id' => $customer->id, 'domain' => 'schaduw.nl', 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);
    }

    /** The top bar breadcrumb HTML only. */
    private function trail(string $html): string
    {
        preg_match('#<nav class="topbar-crumbs".*?</nav>#s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_admin_domain_page_shows_customer_and_domain_in_the_top_bar(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();

        $res = $this->get("/admin/websites/{$site->id}")->assertOk();
        $trail = $this->trail($res->getContent());
        $this->assertStringContainsString('href="'.route('admin.websites.index').'"', $trail);
        $this->assertStringContainsString('>Domains<', $trail);
        $this->assertStringContainsString('href="'.route('admin.customers.show', $site->customer).'">Reinder<', $trail);
        $this->assertStringContainsString('<strong class="crumb" aria-current="page">schaduw.nl</strong>', $trail);
        $res->assertSee('has-site-trail', false);
    }

    public function test_wordpress_sub_page_adds_the_page_and_links_back_to_the_domain(): void
    {
        $site = $this->site();
        $this->artisan('whp:wordpress:scan')->assertSuccessful();
        $install = WordPressInstall::where('website_id', $site->id)->firstOrFail();
        $this->actingAsAdmin();

        $trail = $this->trail($this->get("/admin/wordpress/{$install->id}/extensions")->assertOk()->getContent());
        $this->assertStringContainsString('href="'.route('admin.websites.show', $site).'">schaduw.nl<', $trail);
        $this->assertStringContainsString('href="'.route('admin.customers.show', $site->customer).'"', $trail);
        $this->assertStringContainsString('aria-current="page">Plugins &amp; themes</strong>', $trail);
    }

    public function test_client_trail_links_to_own_websites_without_customer(): void
    {
        $site = $this->site();
        $user = User::factory()->create(['customer_id' => $site->customer_id, 'locale' => 'nl']);
        $this->actingAs($user);

        $res = $this->withSession(['locale' => 'nl'])->get("/websites/{$site->id}")->assertOk();
        $trail = $this->trail($res->getContent());
        $this->assertStringContainsString('href="'.route('client.websites.index').'"', $trail);
        $this->assertStringContainsString('aria-current="page">schaduw.nl</strong>', $trail);
        $this->assertStringNotContainsString('admin/customers', $trail);
    }

    public function test_pages_without_a_website_keep_the_plain_trail(): void
    {
        $this->actingAsAdmin();

        $res = $this->get('/admin/websites')->assertOk();
        $this->assertStringNotContainsString('class="crumb"', $this->trail($res->getContent()));
        $res->assertDontSee('has-site-trail', false);
    }
}
