<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Rhis\ObjectCache\Models\ObjectCache;
use Rhis\ObjectCache\ObjectCacheManager;
use Tests\TestCase;

class ObjectCacheTest extends TestCase
{
    use RefreshDatabase;

    private function calls(string $action): Collection
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    private function site(string $domain = 'klant.nl', ?Customer $customer = null): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'ssl_status' => 'active',
            'system_user' => 's_'.str_replace('.', '_', $domain)]);
    }

    private function client(Customer $customer): User
    {
        $user = User::factory()->create(['customer_id' => $customer->id, 'role' => 'client']);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    public function test_memory_comes_from_the_package(): void
    {
        $site = $this->site();
        $this->assertSame(64, ObjectCacheManager::memoryFor($site), 'no package: server default');
        $this->withPackage($site, $package = HostingPackage::create(['name' => 'Pro', 'object_cache_mb' => 256]));
        $this->assertSame(256, ObjectCacheManager::memoryFor($site->fresh()));
        $package->update(['object_cache_mb' => 0]);
        $this->assertNull(ObjectCacheManager::memoryFor($site->fresh()));
        $this->assertFalse(ObjectCacheManager::allowed($site->fresh()));
    }

    public function test_customer_switches_the_cache_on_and_off(): void
    {
        $site = $this->site();
        $this->withPackage($site, HostingPackage::create(['name' => 'Pro', 'object_cache_mb' => 128]));
        $this->client($site->customer);

        $this->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('Object cache')->assertSee('128 MB');
        $this->put("/object-cache/{$site->id}", ['enabled' => '1', 'wordpress' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $apply = $this->calls('objcache.apply')->last();
        $this->assertSame(['domain' => 'klant.nl', 'enabled' => true, 'memory_mb' => 128, 'wordpress' => true], $apply['params']);
        $cache = ObjectCache::firstOrFail();
        $this->assertTrue($cache->enabled);
        $this->assertSame('running', $cache->state);
        $this->assertSame(128, $cache->memory_mb);

        $this->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('Hit ratio')->assertSee('Flush cache')
            ->assertSee('/run/whp-objcache/s_klant_nl/valkey.sock');

        $this->post("/object-cache/{$site->id}/flush")->assertRedirect()->assertSessionHas('status');
        $this->assertCount(1, $this->calls('objcache.flush'));
        $this->assertNotNull($cache->fresh()->flushed_at);

        $this->put("/object-cache/{$site->id}", ['enabled' => '0', 'wordpress' => '1'])->assertRedirect();
        $this->assertFalse($this->calls('objcache.apply')->last()['params']['enabled']);
        $this->assertSame('off', $cache->fresh()->state);
        $this->assertDatabaseHas('activity_logs', ['action' => 'object_cache.enabled', 'subject' => 'klant.nl']);
    }

    public function test_customer_cannot_touch_another_website(): void
    {
        $mine = $this->site('mijn.nl');
        $other = $this->site('ander.nl');
        $this->client($mine->customer);
        $this->put("/object-cache/{$other->id}", ['enabled' => '1'])->assertNotFound();
        $this->post("/object-cache/{$other->id}/flush")->assertNotFound();
        $this->assertCount(0, $this->calls('objcache.apply'));
    }

    public function test_package_without_object_cache(): void
    {
        $site = $this->site();
        $this->withPackage($site, HostingPackage::create(['name' => 'Basis', 'object_cache_mb' => 0]));
        $this->client($site->customer);
        $this->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertDontSee('Object cache (Valkey)');
        $this->put("/object-cache/{$site->id}", ['enabled' => '1'])->assertSessionHasErrors('object_cache');
        $this->assertCount(0, $this->calls('objcache.apply'));
    }

    public function test_agent_failure_is_shown(): void
    {
        $site = $this->site();
        $this->agent()->respond('objcache.apply', new AgentException('command_failed', 'The object cache did not start'));
        $this->actingAsAdmin();
        $this->put("/admin/object-cache/{$site->id}", ['enabled' => '1'])->assertRedirect();
        $cache = ObjectCache::firstOrFail();
        $this->assertSame('failed', $cache->state);
        $this->get("/admin/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('The object cache did not start');
    }

    public function test_admin_overview_and_package_form(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->get('/admin/object-cache')->assertOk()->assertSee('No website uses the object cache yet');
        $this->put("/admin/object-cache/{$site->id}", ['enabled' => '1'])->assertRedirect();
        $this->get('/admin/object-cache')->assertOk()->assertSee('klant.nl')->assertSee('Flush cache');
        $this->post("/admin/object-cache/{$site->id}/flush")->assertRedirect()->assertSessionHas('status');

        $this->get('/admin/packages/create')->assertOk()->assertSee('unlimited[object_cache_mb]', false);
        $this->post('/admin/packages', ['name' => 'Cache', 'traffic_action' => 'notify',
            'unlimited' => array_fill_keys(HostingPackage::limitKeys(), 1), 'object_cache_mb' => 512])->assertSessionHasNoErrors();
        $this->assertSame(512, HostingPackage::where('name', 'Cache')->value('object_cache_mb'));
        $this->post('/admin/packages', ['name' => 'Default', 'traffic_action' => 'notify',
            'unlimited' => array_fill_keys(HostingPackage::limitKeys(), 1) + ['object_cache_mb' => 1]])->assertSessionHasNoErrors();
        $this->assertNull(HostingPackage::where('name', 'Default')->value('object_cache_mb'));
    }

    public function test_package_change_restarts_with_new_memory(): void
    {
        $site = $this->site();
        $this->withPackage($site, $package = HostingPackage::create(['name' => 'Pro', 'object_cache_mb' => 128]));
        $this->actingAsAdmin();
        $this->put("/admin/object-cache/{$site->id}", ['enabled' => '1'])->assertRedirect();
        $package->update(['object_cache_mb' => 512]);
        $this->assertSame(512, $this->calls('objcache.apply')->last()['params']['memory_mb']);
        $this->assertSame(512, ObjectCache::firstOrFail()->memory_mb);

        // Package without object cache any more: switched off on the server.
        $package->update(['object_cache_mb' => 0]);
        $this->assertFalse($this->calls('objcache.apply')->last()['params']['enabled']);
    }

    public function test_follows_suspend_and_remove(): void
    {
        $site = $this->site();
        $this->actingAsAdmin();
        $this->put("/admin/object-cache/{$site->id}", ['enabled' => '1'])->assertRedirect();
        $site->update(['status' => 'suspended']);
        $this->assertTrue($this->calls('objcache.suspend')->last()['params']['suspended']);
        $site->update(['status' => 'active']);
        $this->assertFalse($this->calls('objcache.suspend')->last()['params']['suspended']);
        $site->update(['status' => 'removing']);
        $this->assertCount(1, $this->calls('objcache.remove'));
    }
}
