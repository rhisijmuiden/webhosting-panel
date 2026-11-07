<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Rhis\Apps\Models\AppInstallation;
use Rhis\Apps\PluginSets\PluginSet;
use Tests\TestCase;

class WpPluginSetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.wordpress.org/plugins/*' => fn ($request) => str_contains(urldecode($request->url()), 'request[slug]=wordfence')
                ? Http::response(['name' => 'Wordfence Security &#8211; Firewall', 'slug' => 'wordfence'])
                : Http::response(['error' => 'Plugin not found.'], 404),
            'api.wordpress.org/themes/*' => fn ($request) => str_contains(urldecode($request->url()), 'request[slug]=generatepress')
                ? Http::response(['name' => 'GeneratePress', 'slug' => 'generatepress'])
                : Http::response(['error' => 'Theme not found'], 200),
        ]);
    }

    private function site(Customer $customer, string $domain = 'klant.test'): Website
    {
        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active']);
    }

    private function form(Website $site, array $over = []): array
    {
        return array_merge([
            'website' => $site->id, 'path' => '', 'title' => 'Bakkerij Jansen', 'admin_user' => 'jansen',
            'admin_email' => 'jan@klant.test', 'admin_password' => 'Zeer-geheim-2026!', 'locale' => 'nl',
        ], $over);
    }

    private function set(array $over = []): PluginSet
    {
        return PluginSet::create(array_merge([
            'name' => 'RHIS standard', 'plugins' => [['source' => 'wordfence', 'activate' => true, 'name' => 'Wordfence Security']],
            'themes' => [['source' => 'generatepress', 'activate' => true]], 'remove_default_content' => true,
            'remove_inactive_themes' => true, 'customer_visible' => true,
        ], $over));
    }

    public function test_admin_creates_a_set_with_names_from_wordpress_org(): void
    {
        $this->actingAsAdmin();
        $basic = HostingPackage::create(['name' => 'Basic']);
        $this->get('/admin/apps')->assertOk()->assertSee(route('admin.apps.sets.index'), false);
        $this->get('/admin/apps/plugin-sets')->assertOk()->assertSee('No plugin sets yet');
        $this->get('/admin/apps/plugin-sets/new')->assertOk()->assertSee('Basic');

        $res = $this->post('/admin/apps/plugin-sets', [
            'name' => 'RHIS standard', 'description' => 'Our base',
            'plugins' => [
                ['source' => 'wordfence', 'activate' => '1'],
                ['source' => ''],                                   // empty row: ignored
                ['source' => 'https://downloads.example.com/pro/pro-forms.zip?key=1'],
                ['source' => 'wordfence'],                          // duplicate: ignored
            ],
            'themes' => [['source' => 'astra-typo'], ['source' => 'generatepress']],
            'theme_active' => '1',
            'remove_default_content' => '1', 'customer_visible' => '1',
            'packages' => [$basic->id],
        ]);
        $set = PluginSet::sole();
        $res->assertRedirect(route('admin.apps.sets.edit', $set))->assertSessionHas('warning', fn ($w) => str_contains($w, 'astra-typo'));
        $this->assertSame([
            ['source' => 'wordfence', 'activate' => true, 'name' => 'Wordfence Security – Firewall'],
            ['source' => 'https://downloads.example.com/pro/pro-forms.zip?key=1', 'activate' => false],
        ], $set->plugins);
        $this->assertSame([
            ['source' => 'astra-typo', 'activate' => false],
            ['source' => 'generatepress', 'activate' => true, 'name' => 'GeneratePress'],
        ], $set->themes);
        $this->assertTrue($set->remove_default_content);
        $this->assertFalse($set->remove_inactive_themes);
        $this->assertSame([$basic->id => $set->id], PluginSet::defaultsByPackage());

        $this->get('/admin/apps/plugin-sets')->assertOk()->assertSee('RHIS standard')->assertSee('Wordfence Security')
            ->assertSee('pro-forms')->assertSee('Default for Basic');
        $this->get(route('admin.apps.sets.edit', $set))->assertOk()->assertSee('pro-forms.zip', false);
    }

    public function test_validation(): void
    {
        $this->actingAsAdmin();
        $base = ['name' => 'X', 'themes' => []];
        $this->post('/admin/apps/plugin-sets', $base + ['plugins' => []])->assertSessionHasErrors('plugins');
        foreach (['--exec=id', 'Wordfence', 'http://example.com/a.zip', 'https://example.com/a.php', '../x'] as $bad) {
            $this->post('/admin/apps/plugin-sets', $base + ['plugins' => [['source' => $bad]]])->assertSessionHasErrors('plugins.0.source');
        }
        $this->assertSame(0, PluginSet::count());
    }

    public function test_a_package_has_one_default_set(): void
    {
        $this->actingAsAdmin();
        $basic = HostingPackage::create(['name' => 'Basic']);
        $first = $this->set();
        $second = $this->set(['name' => 'Shop']);
        $this->put(route('admin.apps.sets.update', $first), ['name' => 'RHIS standard', 'plugins' => [['source' => 'wordfence']], 'packages' => [$basic->id]])->assertRedirect();
        $this->put(route('admin.apps.sets.update', $second), ['name' => 'Shop', 'plugins' => [['source' => 'wordfence']], 'packages' => [$basic->id]])->assertRedirect();
        $this->assertSame([$basic->id => $second->id], PluginSet::defaultsByPackage());
        // The stored wordpress.org name is kept, no new lookup needed.
        $this->assertSame('Wordfence Security', $first->fresh()->plugins[0]['name']);

        $this->delete(route('admin.apps.sets.destroy', $second))->assertRedirect(route('admin.apps.sets.index'));
        $this->assertSame([], PluginSet::defaultsByPackage());
    }

    public function test_install_sends_the_set_and_shows_the_report(): void
    {
        $this->actingAsAdmin();
        $package = HostingPackage::create(['name' => 'Basic']);
        $site = $this->withPackage($this->site(Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test'])), $package);
        $set = $this->set();
        $set->packages()->attach($package->id);

        // The package default is preselected on the form.
        $this->get('/admin/apps/install/wordpress?website='.$site->id)->assertOk()->assertSee('Plugins and themes')
            ->assertSee('<option value="'.$set->id.'" selected', false);
        $this->get('/admin/apps/install/joomla')->assertOk()->assertDontSee('Plugins and themes');

        $this->post('/admin/apps/install/wordpress', $this->form($site, ['plugin_set' => $set->id]))->assertRedirect();
        $call = collect($this->agent()->calls)->firstWhere('action', 'apps.install');
        $this->assertSame([
            'name' => 'RHIS standard',
            'plugins' => [['source' => 'wordfence', 'activate' => true]],
            'themes' => [['source' => 'generatepress', 'activate' => true]],
            'remove_default_content' => true, 'remove_inactive_themes' => true,
        ], $call['params']['plugin_set']);
        $installation = AppInstallation::sole();
        $this->assertSame($set->id, $installation->plugin_set_id);
        $this->assertSame('RHIS standard', $installation->plugin_set_name);

        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'id' => 'ab12cd34', 'version' => '6.9', 'url' => 'https://klant.test', 'admin_url' => 'https://klant.test/wp-admin/',
            'database' => 'klant_test_wordpres_1a2b', 'page_cache_disabled' => false,
            'plugin_set' => ['name' => 'RHIS standard', 'failed' => 1, 'warnings' => [], 'removed_themes' => ['twentytwentyfour'], 'items' => [
                ['type' => 'theme', 'name' => 'generatepress', 'ok' => true, 'activated' => true],
                ['type' => 'plugin', 'name' => 'wordfence', 'ok' => false, 'activated' => false, 'error' => 'Download failed.'],
            ]],
        ]]);
        $this->get(route('admin.apps.show', $installation))->assertOk()->assertSee('Plugin set: RHIS standard')
            ->assertSee('Download failed.')->assertSee('1 item of the set could not be installed')->assertSee('twentytwentyfour');
        $this->assertSame(1, $installation->fresh()->plugin_set_report['failed']);
        $this->get('/admin/apps/plugin-sets')->assertSee('Used for 1 site');
    }

    public function test_no_set_and_other_apps_send_nothing(): void
    {
        $this->actingAsAdmin();
        $site = $this->site(Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']));
        $set = $this->set();
        $this->post('/admin/apps/install/wordpress', $this->form($site, ['plugin_set' => '']))->assertRedirect();
        $this->post('/admin/apps/install/joomla', $this->form($site, ['path' => 'portal', 'locale' => 'en', 'plugin_set' => $set->id]))->assertRedirect();
        foreach (collect($this->agent()->calls)->where('action', 'apps.install') as $call) {
            $this->assertArrayNotHasKey('plugin_set', $call['params']);
        }
        $this->post('/admin/apps/install/wordpress', $this->form($site, ['path' => 'blog', 'plugin_set' => 9999]))->assertSessionHasErrors('plugin_set');
    }

    public function test_customers_see_their_sets_and_the_package_default_only(): void
    {
        $package = HostingPackage::create(['name' => 'Basic']);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.test']);
        $site = $this->withPackage($this->site($customer), $package);
        $public = $this->set(['name' => 'Blog basics']);
        $hidden = $this->set(['name' => 'Internal tools', 'customer_visible' => false]);
        $default = $this->set(['name' => 'Package default', 'customer_visible' => false]);
        $default->packages()->attach($package->id);
        $this->actingAs(User::factory()->create(['customer_id' => $customer->id]));

        $this->get('/apps/install/wordpress')->assertOk()->assertSee('Blog basics')->assertSee('Package default')
            ->assertDontSee('Internal tools')->assertDontSee(route('admin.apps.sets.index'), false);
        $this->post('/apps/install/wordpress', $this->form($site, ['plugin_set' => $hidden->id]))->assertSessionHasErrors('plugin_set');
        $this->post('/apps/install/wordpress', $this->form($site, ['plugin_set' => $default->id]))->assertRedirect();
        $this->assertSame($default->id, AppInstallation::sole()->plugin_set_id);
        $this->get('/admin/apps/plugin-sets')->assertForbidden();
        $this->assertNotNull($public);
    }

    public function test_dutch_interface(): void
    {
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $this->set();
        $this->get('/admin/apps/plugin-sets')->assertOk()->assertSee('WordPress-pluginsets')->assertSee('Klanten kunnen hem kiezen');
        $this->get('/admin/apps/plugin-sets/new')->assertOk()->assertSee('Plugin toevoegen')->assertSee('Standaard voor pakketten');
    }
}
