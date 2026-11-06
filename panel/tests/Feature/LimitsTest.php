<?php

namespace Tests\Feature;

use App\Mail\TrafficLimitReached;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteTraffic;
use App\Services\PhpSettings;
use App\Services\Traffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LimitsTest extends TestCase
{
    use RefreshDatabase;

    private function calls(string $action)
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    public function test_new_package_is_unlimited_by_default(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/packages/create')->assertOk()->assertSee('Server resources')->assertSee('unlimited[disk_mb]', false);

        $this->post('/admin/packages', [
            'name' => 'Onbeperkt', 'traffic_action' => 'notify', 'is_active' => 1,
            'unlimited' => array_fill_keys(HostingPackage::limitKeys(), 1),
        ])->assertRedirect('/admin/packages');
        $p = HostingPackage::firstOrFail();
        foreach (HostingPackage::limitKeys() as $key) {
            $this->assertNull($p->{$key}, $key);
        }
        $this->assertSame('Unlimited', $p->limitLabel('max_mailboxes'));
        $this->get('/admin/packages')->assertSee('Unlimited')->assertSee('Fair share');
    }

    public function test_package_limits_and_validation(): void
    {
        $this->actingAsAdmin();
        $unlimited = array_fill_keys(['max_cronjobs', 'io_mbps', 'max_processes', 'php_workers', 'db_connections', 'max_databases'], 1);
        $this->post('/admin/packages', ['name' => 'Pro', 'traffic_action' => 'suspend', 'unlimited' => $unlimited,
            'disk_mb' => 20000, 'traffic_gb_month' => 500, 'max_websites' => 5, 'max_mailboxes' => 50, 'cpu_percent' => 200, 'memory_mb' => 2048,
        ])->assertRedirect();
        $p = HostingPackage::firstOrFail();
        $this->assertSame(500, $p->traffic_gb_month);
        $this->assertSame(200, $p->cpu_percent);
        $this->assertNull($p->max_databases);
        $this->assertTrue($p->hasResourceCaps());

        // A limit without "unlimited" must have a value.
        $this->post('/admin/packages', ['name' => 'Kapot', 'traffic_action' => 'notify'])->assertSessionHasErrors(['disk_mb', 'cpu_percent']);
        $this->post('/admin/packages', ['name' => 'Kapot', 'traffic_action' => 'nuke', 'unlimited' => array_fill_keys(HostingPackage::limitKeys(), 1)])
            ->assertSessionHasErrors('traffic_action');
    }

    public function test_changing_resource_caps_applies_them_to_customers(): void
    {
        $this->actingAsAdmin();
        $p = HostingPackage::create(['name' => 'Start']);
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $p->id]);
        Website::create(['customer_id' => $c->id, 'domain' => 'jan.nl', 'status' => 'active']);

        $unlimited = array_fill_keys(array_diff(HostingPackage::limitKeys(), ['cpu_percent', 'memory_mb', 'db_connections']), 1);
        $this->put("/admin/packages/{$p->id}", ['name' => 'Start', 'traffic_action' => 'notify', 'unlimited' => $unlimited,
            'cpu_percent' => 150, 'memory_mb' => 1024, 'db_connections' => 20])->assertRedirect();

        $slice = $this->calls('customer.limits')->last()['params'];
        $this->assertSame(['customer_id' => $c->id, 'cpu_percent' => 150, 'memory_mb' => 1024, 'io_mbps' => null, 'max_processes' => null], $slice);
        $this->assertSame(['domain' => 'jan.nl', 'customer_id' => $c->id], $this->calls('site.owner')->last()['params']);
        $this->assertSame(20, $this->calls('db.limits')->last()['params']['max_connections']);
        $this->assertSame('jan.nl', $this->calls('site.php_settings')->last()['params']['domain']);
    }

    public function test_switching_package_applies_new_limits(): void
    {
        $a = HostingPackage::create(['name' => 'A']);
        $b = HostingPackage::create(['name' => 'B', 'memory_mb' => 512]);
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $a->id]);
        Website::create(['customer_id' => $c->id, 'domain' => 'jan.nl', 'status' => 'active']);
        $c->update(['hosting_package_id' => $b->id]);
        $this->assertSame(512, $this->calls('customer.limits')->last()['params']['memory_mb']);
    }

    public function test_php_server_settings_and_site_values_within_maximum(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/php')->assertOk()->assertSee('memory_limit')->assertSee('Maximum for customers');

        $form = ['workers' => 8, 'display_errors' => 0, 'customer_display_errors' => 1];
        foreach (PhpSettings::KEYS as $key => [, , $default, $max]) {
            $form[$key] = ['default' => $default, 'max' => $max];
        }
        $form['memory_limit'] = ['default' => 256, 'max' => 512];
        $this->put('/admin/php', $form)->assertRedirect();
        $this->assertSame(512, app(PhpSettings::class)->server()['memory_limit']['max']);

        // Default above maximum is refused.
        $this->put('/admin/php', ['memory_limit' => ['default' => 900, 'max' => 512]] + $form)->assertSessionHasErrors('memory_limit.max');

        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $user = User::factory()->create(['customer_id' => $c->id, 'role' => User::ROLE_CLIENT]);
        $site = Website::create(['customer_id' => $c->id, 'domain' => 'jan.nl', 'status' => 'active']);
        $this->actingAs($user);
        $this->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('PHP settings');

        $this->put("/websites/{$site->id}/php", ['php' => ['memory_limit' => 2048]])->assertSessionHasErrors('php.memory_limit');
        $this->put("/websites/{$site->id}/php", ['php' => ['memory_limit' => 384, 'upload_max_filesize' => 256, 'display_errors' => 'on']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['memory_limit' => 384, 'upload_max_filesize' => 256, 'display_errors' => true], $site->fresh()->php_settings);
        $params = $this->calls('site.php_settings')->last()['params'];
        $this->assertSame('384M', $params['settings']['memory_limit']);
        $this->assertSame('256M', $params['settings']['upload_max_filesize']);
        $this->assertSame('256M', $params['settings']['post_max_size'], 'POST size grows with the upload size');
        $this->assertSame('on', $params['settings']['display_errors']);
        $this->assertSame(8, $params['php_workers']);

        // Another customer's website is not reachable.
        $other = Website::create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@example.test'])->id, 'domain' => 'x.nl', 'status' => 'active']);
        $this->put("/websites/{$other->id}/php", ['php' => ['memory_limit' => 300]])->assertNotFound();
    }

    public function test_extensions_page_and_actions(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/php?tab=extensions&php=8.5')->assertOk()->assertSee('MongoDB driver')->assertSee('PECL extensions');
        $this->post('/admin/php/extensions', ['php' => '8.5', 'name' => 'mongodb', 'source' => 'apt'])
            ->assertRedirect('/admin/php?tab=extensions&php=8.5')->assertSessionHas('php_job');
        $this->assertSame(['php' => '8.5', 'name' => 'mongodb', 'source' => 'apt'], $this->calls('php.extensions.install')->last()['params']);
        $this->post('/admin/php/extensions', ['php' => '8.5', 'name' => 'x; reboot', 'source' => 'apt'])->assertSessionHasErrors('name');
        $this->getJson('/admin/php/extensions/search?q=swoole')->assertOk()->assertJsonPath('results.0.name', 'swoole');
        $this->post('/admin/php/extensions/toggle', ['php' => '8.4', 'name' => 'redis', 'enabled' => 0])->assertRedirect();
        $this->assertFalse($this->calls('php.extensions.toggle')->last()['params']['enabled']);
    }

    public function test_traffic_is_stored_and_limit_suspends(): void
    {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        $p = HostingPackage::create(['name' => 'Klein', 'traffic_gb_month' => 1, 'traffic_action' => 'suspend']);
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $p->id]);
        $site = Website::create(['customer_id' => $c->id, 'domain' => 'jan.nl', 'status' => 'active']);
        $month = now()->utc()->format('Y-m');
        $this->agent()->respond('traffic.report', ['month' => $month, 'sites' => ['jan.nl' => 2 * 1024 ** 3, 'unknown.nl' => 5]]);

        app(Traffic::class)->collect();
        $this->assertSame(2 * 1024 ** 3, WebsiteTraffic::where('website_id', $site->id)->value('bytes'));
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertSame(['domain' => 'jan.nl'], $this->calls('site.suspend')->last()['params']);
        Mail::assertSent(TrafficLimitReached::class, fn ($m) => $m->hasTo('admin@example.test') && $m->suspended);

        // Only once per month.
        app(Traffic::class)->collect();
        $this->assertCount(1, $this->calls('site.suspend'));
        $this->assertSame(200.0, round($c->fresh()->trafficPercent()));

        $this->actingAsAdmin();
        $this->get("/admin/customers/{$c->id}")->assertSee('Put online now');
        $this->post("/admin/customers/{$c->id}/traffic-unsuspend")->assertRedirect();
        $this->assertSame('active', $site->fresh()->status);

        // Next month: the counter starts again and suspended sites come back by themselves.
        $site->update(['status' => 'suspended', 'last_error' => Traffic::SUSPENDED]);
        $this->agent()->respond('traffic.report', ['month' => '2099-01', 'sites' => []]);
        app(Traffic::class)->collect();
        $this->assertSame('active', $site->fresh()->status);
        $this->assertNull($c->fresh()->traffic_alert_month);
    }

    public function test_traffic_notify_keeps_sites_online(): void
    {
        Mail::fake();
        User::factory()->admin()->create();
        $p = HostingPackage::create(['name' => 'Klein', 'traffic_gb_month' => 1]);
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $p->id]);
        $site = Website::create(['customer_id' => $c->id, 'domain' => 'jan.nl', 'status' => 'active']);
        $this->agent()->respond('traffic.report', ['month' => now()->utc()->format('Y-m'), 'sites' => ['jan.nl' => 3 * 1024 ** 3]]);
        app(Traffic::class)->collect();
        $this->assertSame('active', $site->fresh()->status);
        $this->assertCount(0, $this->calls('site.suspend'));
        Mail::assertSent(TrafficLimitReached::class, fn ($m) => ! $m->suspended);
    }

    public function test_crm_unlimited_is_null(): void
    {
        $p = HostingPackage::create(['name' => 'Oud', 'max_mailboxes' => null, 'disk_mb' => null]);
        $limits = \Rhis\CrmApi\Support\Present::planLimits($p);
        $this->assertNull($limits['mailboxes']);
        $this->assertNull($limits['disk_mb']);
        $this->assertArrayHasKey('traffic_gb_month', $limits);
    }
}
