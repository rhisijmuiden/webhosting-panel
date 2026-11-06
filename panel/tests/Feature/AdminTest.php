<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_admin_pages_render(): void
    {
        $this->actingAsAdmin();
        $package = HostingPackage::create(['name' => 'Start']);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = $this->withPackage(Website::create(['customer_id' => $customer->id, 'domain' => 'example.test', 'status' => 'active']), $package);

        foreach (['/admin', '/admin/customers', "/admin/customers/{$customer->id}", '/admin/customers/create',
            "/admin/customers/{$customer->id}/edit", '/admin/packages', '/admin/packages/create', "/admin/packages/{$package->id}/edit",
            '/admin/websites', '/admin/websites/create', "/admin/websites/{$site->id}", '/admin/services', '/admin/security',
            '/admin/firewall', '/admin/settings/mail', '/admin/system', '/admin/activity', '/profile', '/profile/security'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_dashboard_shows_agent_error_when_unreachable(): void
    {
        $this->actingAsAdmin();
        $this->agent()->respond('system.info', AgentException::unreachable('socket missing'));
        $this->get('/admin')->assertOk()->assertSee('The server agent is not reachable.');
    }

    public function test_customer_crud(): void
    {
        $this->actingAsAdmin();
        $this->post('/admin/customers', ['name' => 'Jan', 'company' => 'Bakkerij', 'email' => 'jan@example.test', 'status' => 'active', 'locale' => 'nl'])
            ->assertRedirect();
        $customer = Customer::firstOrFail();
        $this->assertSame('Bakkerij', $customer->displayName());

        $this->post("/admin/customers/{$customer->id}/users", ['name' => 'Jan', 'email' => 'login@example.test', 'password' => 'a-long-password-1', 'locale' => 'nl'])
            ->assertRedirect();
        $this->assertSame($customer->id, User::where('email', 'login@example.test')->first()->customer_id);

        $this->delete("/admin/customers/{$customer->id}")->assertRedirect('/admin/customers');
        $this->assertDatabaseCount('customers', 0);
        $this->assertTrue(ActivityLog::where('action', 'customer.created')->exists());
    }

    public function test_creating_a_website_provisions_it_through_the_agent(): void
    {
        $this->actingAsAdmin();
        $package = HostingPackage::create(['name' => 'Start', 'max_websites' => 1, 'disk_mb' => 5000]);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);

        $this->post('/admin/websites', [
            'customer_id' => $customer->id, 'domain' => 'Example.NL', 'profile' => 'laravel', 'php_version' => '8.5',
            'ssl' => '1', 'www_alias' => '1', 'page_cache' => '1', 'hosting_package_id' => $package->id, 'expires_on' => '2099-12-31',
        ])->assertRedirect();

        $site = Website::firstOrFail();
        $this->assertSame('example.nl', $site->domain);
        $this->assertSame('active', $site->status);
        $this->assertSame('active', $site->ssl_status);
        $this->assertFalse($site->page_cache, 'Laravel sites have no page cache');
        $call = collect($this->agent()->calls)->firstWhere('action', 'site.add');
        // Every website is a subscription: on the chosen package, in its own resource slice.
        $sub = $site->subscription;
        $this->assertSame([$package->id, '2099-12-31'], [$sub->hosting_package_id, $sub->expires_on->toDateString()]);
        $this->assertSame(['domain' => 'example.nl', 'php' => '8.5', 'profile' => 'laravel', 'cache' => false, 'ssl' => true, 'no_alias' => false, 'quota_mb' => 5000, 'subscription_id' => $sub->id, 'php_workers' => 10], $call['params']);
        $this->assertSame(['subscription_id' => $sub->id, 'cpu_percent' => null, 'memory_mb' => null, 'io_mbps' => null, 'max_processes' => null],
            collect($this->agent()->calls)->firstWhere('action', 'subscription.limits')['params']);

        // A second website of the same customer is a second subscription, with or without a package.
        $this->post('/admin/websites', ['customer_id' => $customer->id, 'domain' => 'second.nl', 'profile' => 'php', 'php_version' => '8.4'])
            ->assertSessionHasNoErrors();
        $this->assertNull(Website::where('domain', 'second.nl')->sole()->subscription->hosting_package_id);
    }

    public function test_failed_provisioning_is_shown(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $this->agent()->respond('site.add', new AgentException('command_failed', 'whp-site exited with code 1'));
        $this->post('/admin/websites', ['customer_id' => $customer->id, 'domain' => 'broken.nl', 'profile' => 'php', 'php_version' => '8.4']);
        $site = Website::firstOrFail();
        $this->assertSame('failed', $site->status);
        $this->get("/admin/websites/{$site->id}")->assertSee('whp-site exited with code 1');
    }

    public function test_invalid_domain_is_rejected(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        foreach (['-bad.nl', 'no_tld', 'a.nl;rm', '../etc'] as $domain) {
            $this->post('/admin/websites', ['customer_id' => $customer->id, 'domain' => $domain, 'profile' => 'php', 'php_version' => '8.4'])
                ->assertSessionHasErrors('domain');
        }
    }

    public function test_change_php_and_remove_website(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'example.nl', 'status' => 'active', 'php_version' => '8.3']);

        $this->put("/admin/websites/{$site->id}", ['php_version' => '8.5'])->assertRedirect();
        $this->assertSame('8.5', $site->fresh()->php_version);

        $this->delete("/admin/websites/{$site->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->delete("/admin/websites/{$site->id}", ['confirm' => 'example.nl'])->assertRedirect('/admin/websites');
        $this->assertDatabaseCount('websites', 0);
    }

    public function test_firewall_and_crowdsec(): void
    {
        $this->actingAsAdmin();
        $this->post('/admin/firewall', ['type' => 'open_port', 'protocol' => 'tcp', 'port' => 3306, 'comment' => 'db'])->assertSessionHasNoErrors();
        $this->assertSame(['type' => 'open_port', 'protocol' => 'tcp', 'port' => 3306, 'comment' => 'db'], collect($this->agent()->calls)->firstWhere('action', 'firewall.add')['params']);
        $this->post('/admin/firewall', ['type' => 'open_port', 'port' => 99999])->assertSessionHasErrors(['port', 'protocol']);

        $this->post('/admin/security/ban', ['address' => '203.0.113.9', 'duration' => '24h'])->assertSessionHasNoErrors();
        $this->post('/admin/security/ban', ['address' => 'not-an-ip', 'duration' => '24h'])->assertSessionHasErrors('address');
        $this->post('/admin/security/ban', ['address' => '1.2.3.4', 'duration' => '99y'])->assertSessionHasErrors('duration');
    }

    public function test_update_check_and_channel_switch(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/admin/system/check', ['channel' => 'test'])->assertOk()->assertJsonPath('update_available', false);
        $this->postJson('/admin/system/channel', ['channel' => 'beta'])->assertUnprocessable();
        $this->postJson('/admin/system/channel', ['channel' => 'test'])->assertOk()->assertJsonStructure(['job']);
        $this->postJson('/admin/system/update')->assertOk()->assertJsonStructure(['job']);

        $this->agent()->respond('panel.check_update', new AgentException('command_failed', 'git fetch failed'));
        $this->postJson('/admin/system/check')->assertStatus(502)->assertJsonPath('error.message', 'git fetch failed');
    }

    public function test_info_endpoint_hides_details_from_clients(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/info')->assertOk()->assertJsonStructure(['version', 'channel', 'commit', 'php']);

        $customer = Customer::create(['name' => 'X', 'email' => 'x@example.test']);
        $client = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($client)->getJson('/info')->assertOk()->assertJsonMissingPath('commit');
    }
}
