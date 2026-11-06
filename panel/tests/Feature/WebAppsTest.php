<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Rhis\WebApps\Models\WebApp;
use Tests\TestCase;

class WebAppsTest extends TestCase
{
    use RefreshDatabase;

    private function calls(string $action): Collection
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    private function site(string $domain = 'klant.nl', ?Customer $customer = null): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active']);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Shop API', 'runtime' => 'node', 'runtime_version' => '24', 'app_root' => 'app', 'mount_path' => '/api',
            'start_command' => 'npm start', 'build_command' => 'npm run build', 'deps_on_deploy' => '1',
            'env' => [['key' => 'DATABASE_URL', 'value' => 'mysql://u:p@localhost/shop'], ['key' => '', 'value' => '']],
        ];
    }

    private function client(Customer $customer): User
    {
        $user = User::factory()->create(['customer_id' => $customer->id, 'role' => 'client']);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    public function test_admin_creates_an_app_with_package_limits(): void
    {
        $site = $this->site();
        $this->withPackage($site, HostingPackage::create(['name' => 'Pro', 'webapp_memory_mb' => 512, 'webapp_cpu_percent' => 100]));
        $this->actingAsAdmin();
        $this->get('/admin/webapps')->assertOk()->assertSee('Node.js &amp; Python', false);
        $this->get('/admin/webapps/create')->assertOk()->assertSee('Start command');

        $this->post('/admin/webapps', $this->form(['website_id' => $site->id]))->assertSessionHasNoErrors()->assertRedirect();
        $app = WebApp::firstOrFail();
        $this->assertSame(['DATABASE_URL' => 'mysql://u:p@localhost/shop'], $app->env);
        $this->assertStringNotContainsString('mysql://', (string) \DB::table('web_apps')->value('env'), 'env is encrypted at rest');

        $p = $this->calls('webapp.apply')->last()['params'];
        $this->assertSame($app->uid, $p['id']);
        $this->assertSame('klant.nl', $p['domain']);
        $this->assertSame('/api', $p['mount']);
        $this->assertSame(512, $p['memory_mb']);
        $this->assertSame(100, $p['cpu_percent']);
        $this->assertTrue($p['enabled']);
        $this->assertNotNull($app->port);

        $this->get("/admin/webapps/{$app->id}")->assertOk()->assertSee('Shop API')->assertSee('Install dependencies')->assertSee('Server listening');
        $this->get("/admin/webapps/{$app->id}/poll")->assertOk()->assertJsonPath('status.state', 'running');
    }

    public function test_validation_and_conflicts(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'app_root' => 'git/x']))->assertSessionHasErrors('app_root');
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'app_root' => '../etc']))->assertSessionHasErrors('app_root');
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'env' => [['key' => 'PORT', 'value' => '80']]]))->assertSessionHasErrors('env.0.key');
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'runtime_version' => '26']))->assertSessionHasErrors('runtime_version');
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'start_command' => "npm start\nrm -rf /"]))->assertSessionHasErrors('start_command');
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id]))->assertSessionHasNoErrors();
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id, 'name' => 'Tweede']))->assertSessionHasErrors('mount_path');
        $this->assertSame(1, WebApp::count());
    }

    public function test_agent_failure_does_not_leave_an_app(): void
    {
        $site = $this->site();
        $this->agent()->respond('webapp.apply', new AgentException('conflict', 'Node.js 24 is not installed on this server'));
        $this->actingAsAdmin();
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id]))->assertSessionHasErrors('agent');
        $this->assertSame(0, WebApp::count());
    }

    public function test_package_limit_for_customers(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = $this->withPackage($this->site('jan.nl', $customer), HostingPackage::create(['name' => 'Basis', 'max_webapps' => 0]));
        $this->client($customer);
        $this->get('/webapps/create')->assertNotFound();

        $site->subscription->package->update(['max_webapps' => 1]);
        $this->get('/webapps/create')->assertOk();
        $this->post('/webapps', $this->form(['website_id' => $site->id, 'mount_path' => '/']))->assertSessionHasNoErrors();
        $this->post('/webapps', $this->form(['website_id' => $site->id]))->assertSessionHasErrors('name');
        $this->assertSame(1, WebApp::count());
    }

    public function test_customers_only_see_their_own_apps(): void
    {
        $mine = Customer::create(['name' => 'Mijn', 'email' => 'm@example.test']);
        $other = $this->site('ander.nl');
        $app = WebApp::create(['website_id' => $other->id, 'uid' => 'abcdef0123', 'name' => 'Ander', 'runtime' => 'python',
            'runtime_version' => '3.13', 'start_command' => 'python app.py']);
        $this->site('mijn.nl', $mine);
        $this->client($mine);
        $this->get('/webapps')->assertOk()->assertDontSee('Ander');
        $this->get("/webapps/{$app->id}")->assertNotFound();
        $this->post("/webapps/{$app->id}/control", ['op' => 'stop'])->assertNotFound();
    }

    public function test_controls_logs_install_and_remove(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id]));
        $app = WebApp::firstOrFail();

        $this->post("/admin/webapps/{$app->id}/control", ['op' => 'restart'])->assertSessionHasNoErrors();
        $this->assertSame(['id' => $app->uid, 'op' => 'restart'], $this->calls('webapp.control')->last()['params']);

        $this->post("/admin/webapps/{$app->id}/control", ['op' => 'stop']);
        $this->assertFalse($app->fresh()->enabled);
        $this->assertFalse($this->calls('webapp.apply')->last()['params']['enabled']);
        $this->post("/admin/webapps/{$app->id}/control", ['op' => 'start']);
        $this->assertTrue($app->fresh()->enabled);

        $this->post("/admin/webapps/{$app->id}/install")->assertSessionHasNoErrors();
        $this->assertNotNull($app->fresh()->install_job);

        $this->put("/admin/webapps/{$app->id}", $this->form(['mount_path' => '/', 'runtime' => 'python', 'runtime_version' => '3.13',
            'start_command' => 'uvicorn main:app --host 127.0.0.1 --port $PORT']))->assertSessionHasNoErrors();
        $p = $this->calls('webapp.apply')->last()['params'];
        $this->assertSame('python', $p['runtime']);
        $this->assertSame('/', $p['mount']);

        $this->delete("/admin/webapps/{$app->id}", ['remove_deps' => '1'])->assertRedirect('/admin/webapps');
        $this->assertSame(['id' => $app->uid, 'remove_deps' => true], $this->calls('webapp.remove')->last()['params']);
        $this->assertSame(0, WebApp::count());
    }

    public function test_suspend_package_change_and_removal_follow_the_website(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->post('/admin/webapps', $this->form(['website_id' => $site->id]));

        $site->update(['status' => 'suspended']);
        $this->assertSame(['domain' => 'klant.nl', 'suspended' => true], $this->calls('webapp.suspend')->last()['params']);
        $site->update(['status' => 'active']);
        $this->assertSame(['domain' => 'klant.nl', 'suspended' => false], $this->calls('webapp.suspend')->last()['params']);

        $before = $this->calls('webapp.apply')->count();
        $this->withPackage($site, HostingPackage::create(['name' => 'Groot', 'webapp_memory_mb' => 2048]));
        $this->assertSame($before + 1, $this->calls('webapp.apply')->count());
        $this->assertSame(2048, $this->calls('webapp.apply')->last()['params']['memory_mb']);

        $site->update(['status' => 'removing']);
        $this->assertCount(1, $this->calls('webapp.remove'));
        $this->assertSame(0, WebApp::count());
    }

    public function test_runtime_versions_page(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/webapps/runtimes')->assertOk()->assertSee('v24.11.0')->assertSee('LTS Krypton')->assertSee('Not installed');
        $this->post('/admin/webapps/runtimes', ['runtime' => 'node', 'version' => '26'])->assertSessionHasNoErrors();
        $this->assertSame(['runtime' => 'node', 'version' => '26'], $this->calls('runtime.install')->last()['params']);
        $this->post('/admin/webapps/runtimes', ['runtime' => 'ruby', 'version' => '3'])->assertSessionHasErrors('runtime');

        WebApp::create(['website_id' => $this->site()->id, 'uid' => 'abcdef0123', 'name' => 'X', 'runtime' => 'node', 'runtime_version' => '22', 'start_command' => 'npm start']);
        $this->delete('/admin/webapps/runtimes', ['runtime' => 'node', 'version' => '22'])->assertSessionHasErrors('agent');
        $this->assertCount(0, $this->calls('runtime.remove'));
    }

    public function test_package_form_has_app_limits(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/packages/create')->assertOk()->assertSee('unlimited[max_webapps]', false);
        $this->post('/admin/packages', ['name' => 'Apps', 'traffic_action' => 'notify', 'unlimited' => array_fill_keys(HostingPackage::limitKeys(), 1) + ['webapp_cpu_percent' => 1],
            'max_webapps' => 3, 'webapp_memory_mb' => 1024])->assertSessionHasNoErrors();
        $p = HostingPackage::where('name', 'Apps')->firstOrFail();
        $this->assertSame(3, $p->max_webapps);
        $this->assertSame(1024, $p->webapp_memory_mb);
        $this->assertNull($p->webapp_cpu_percent);
    }

    public function test_dutch_screens(): void
    {
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $this->get('/admin/webapps/create')->assertOk()->assertSee('Startcommando')->assertSee('Omgevingsvariabelen');
        $this->get('/admin/webapps/runtimes')->assertOk()->assertSee('Runtime-versies');
    }
}
