<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Apps\Models\AppInstallation;
use Tests\TestCase;

class AppsTest extends TestCase
{
    use RefreshDatabase;

    private function site(Customer $customer, string $domain = 'klant.test', string $status = 'active'): Website
    {
        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => $status]);
    }

    private function form(Website $site, array $over = []): array
    {
        return array_merge([
            'website' => $site->id, 'path' => '', 'title' => 'Bakkerij Jansen', 'admin_user' => 'jansen',
            'admin_email' => 'jan@klant.test', 'admin_password' => 'Zeer-geheim-2026!', 'locale' => 'nl',
        ], $over);
    }

    public function test_admin_sees_catalog_and_menu(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/apps')->assertOk()->assertSee('WordPress')->assertSee('Joomla')->assertSee('Nextcloud')
            ->assertSee(route('admin.apps.create', 'wordpress'), false);
        $this->get('/admin')->assertSee(route('admin.apps.index'), false);
        $this->get('/admin/apps/install/drupal')->assertNotFound();
    }

    public function test_install_runs_on_the_agent_and_follows_the_job(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']);
        $site = $this->site($customer);
        $this->get('/admin/apps/install/wordpress?website='.$site->id)->assertOk()->assertSee('klant.test');

        $res = $this->post('/admin/apps/install/wordpress', $this->form($site, ['path' => '/blog/']));
        $installation = AppInstallation::sole();
        $res->assertRedirect(route('admin.apps.show', $installation));
        $call = collect($this->agent()->calls)->firstWhere('action', 'apps.install');
        $this->assertSame(['domain' => 'klant.test', 'app' => 'wordpress', 'path' => 'blog', 'title' => 'Bakkerij Jansen',
            'admin_user' => 'jansen', 'admin_email' => 'jan@klant.test', 'admin_password' => 'Zeer-geheim-2026!', 'locale' => 'nl'], $call['params']);
        $this->assertSame('installing', $installation->status);
        $this->assertNotContains('Zeer-geheim-2026!', $installation->getAttributes());

        $this->agent()->respond('job.status', ['status' => 'running', 'log' => ['Downloading WordPress'], 'result' => null, 'error' => null]);
        $this->get(route('admin.apps.show', $installation))->assertOk()->assertSee('http-equiv="refresh"', false)->assertSee('Downloading WordPress');

        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'id' => 'ab12cd34', 'version' => '6.9', 'url' => 'https://klant.test/blog', 'admin_url' => 'https://klant.test/blog/wp-admin/',
            'database' => 'klant_test_wordpres_1a2b', 'page_cache_disabled' => false,
        ]]);
        $this->get(route('admin.apps.show', $installation))->assertOk()->assertSee('https://klant.test/blog/wp-admin/');
        $installation->refresh();
        $this->assertSame('installed', $installation->status);
        $this->assertSame('ab12cd34', $installation->agent_id);

        $this->delete(route('admin.apps.destroy', $installation))->assertRedirect();
        $this->assertSame(['domain' => 'klant.test', 'id' => 'ab12cd34'], collect($this->agent()->calls)->firstWhere('action', 'apps.remove')['params']);
        $this->assertSame('removing', $installation->fresh()->status);
        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => [], 'error' => null, 'result' => ['removed' => true]]);
        $this->get(route('admin.apps.show', $installation))->assertRedirect(route('admin.apps.index'));
        $this->assertSame(0, AppInstallation::count());
    }

    public function test_failed_install_shows_the_error_and_can_be_cleared(): void
    {
        $this->actingAsAdmin();
        $site = $this->site(Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']));
        $this->post('/admin/apps/install/joomla', $this->form($site, ['locale' => 'en']));
        $installation = AppInstallation::sole();
        $this->agent()->respond('job.status', ['status' => 'failed', 'log' => [], 'result' => null, 'error' => ['code' => 'command_failed', 'message' => 'Download failed']]);
        $this->get(route('admin.apps.show', $installation))->assertOk()->assertSee('Download failed');
        $this->assertSame('failed', $installation->fresh()->status);
        $this->delete(route('admin.apps.destroy', $installation))->assertRedirect(route('admin.apps.index'));
        $this->assertFalse(collect($this->agent()->calls)->contains('action', 'apps.remove'));
        $this->assertSame(0, AppInstallation::count());
    }

    public function test_nextcloud_turns_off_the_page_cache(): void
    {
        $this->actingAsAdmin();
        $site = $this->site(Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']));
        $site->update(['page_cache' => true]);
        $this->post('/admin/apps/install/nextcloud', $this->form($site, ['path' => 'cloud']))->assertSessionHasErrors('path');
        $this->post('/admin/apps/install/nextcloud', $this->form($site))->assertRedirect();
        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'id' => '00aa11bb', 'version' => '32.0.1', 'url' => 'https://klant.test', 'admin_url' => 'https://klant.test/index.php/settings/admin',
            'database' => 'klant_test_nextclou_9f9f', 'page_cache_disabled' => true,
        ]]);
        $this->get('/admin/apps')->assertOk();
        $this->assertFalse($site->fresh()->page_cache);
    }

    public function test_validation_and_agent_errors(): void
    {
        $this->actingAsAdmin();
        $site = $this->site(Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']));
        $this->post('/admin/apps/install/wordpress', $this->form($site, ['path' => '../etc']))->assertSessionHasErrors('path');
        $this->post('/admin/apps/install/wordpress', $this->form($site, ['admin_password' => 'kort']))->assertSessionHasErrors('admin_password');
        $this->post('/admin/apps/install/wordpress', $this->form($site, ['admin_user' => 'a b']))->assertSessionHasErrors('admin_user');
        $this->post('/admin/apps/install/joomla', $this->form($site, ['locale' => 'nl']))->assertSessionHasErrors('locale');

        $this->agent()->respond('apps.install', new AgentException('conflict', 'The folder / is not empty (index.php).'));
        $this->post('/admin/apps/install/wordpress', $this->form($site))->assertSessionHasErrors('agent');
        $this->assertSame(0, AppInstallation::count());
    }

    public function test_client_installs_only_into_own_sites_within_the_package(): void
    {
        $package = HostingPackage::create(['name' => 'Start', 'max_databases' => 1]);
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@klant.test', 'hosting_package_id' => $package->id]);
        $other = Customer::create(['name' => 'Other', 'email' => 'other@klant.test']);
        $site = $this->site($mine);
        $foreign = $this->site($other, 'ander.test');
        $this->site($mine, 'nieuw.test', 'provisioning');
        $user = User::factory()->create(['customer_id' => $mine->id]);
        $this->actingAs($user);

        $this->get('/apps')->assertOk()->assertSee('WordPress');
        $this->get('/apps/install/wordpress')->assertOk()->assertSee('klant.test')->assertDontSee('ander.test')->assertDontSee('nieuw.test');
        $this->post('/apps/install/wordpress', $this->form($foreign))->assertNotFound();
        $this->post('/apps/install/wordpress', $this->form($site))->assertRedirect();
        $installation = AppInstallation::sole();
        $this->assertSame($mine->id, $installation->customer_id);

        // The package allows one database: the next install is refused before reaching the agent.
        $this->post('/apps/install/joomla', $this->form($site, ['path' => 'shop', 'locale' => 'en']))->assertSessionHasErrors('agent');
        $this->assertSame(1, collect($this->agent()->calls)->where('action', 'apps.install')->count());

        $theirs = AppInstallation::create(['website_id' => $foreign->id, 'customer_id' => $other->id, 'app' => 'wordpress', 'status' => 'installed', 'agent_id' => 'ffffffff']);
        $this->get(route('client.apps.show', $theirs))->assertNotFound();
        $this->delete(route('client.apps.destroy', $theirs))->assertNotFound();
        $this->get('/admin/apps')->assertForbidden();
    }

    public function test_dutch_interface(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']);
        $this->site($customer);
        $user = User::factory()->create(['customer_id' => $customer->id, 'locale' => 'nl']);
        $this->actingAs($user);
        $this->get('/apps')->assertOk()->assertSee('Geïnstalleerde apps')->assertSee('Installeren');
        $this->get('/apps/install/wordpress')->assertOk()->assertSee('Wachtwoord beheerder');
    }

    public function test_customer_install_can_be_turned_off(): void
    {
        config(['apps.customer_install' => false]);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']);
        $this->actingAs(User::factory()->create(['customer_id' => $customer->id]));
        $this->get('/apps')->assertNotFound();
    }
}
