<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WebRules\Http\WebRulesController;
use Rhis\WebRules\Models\SiteWebRules;
use Tests\TestCase;

/** Redirects & access: redirects, protected folders, error pages, HTTPS/HSTS and blocking per website. */
class WebRulesTest extends TestCase
{
    use RefreshDatabase;

    private function client(): array
    {
        $plan = HostingPackage::create(['name' => 'Basic', 'disk_mb' => 5000, 'max_websites' => 2]);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.nl']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $this->withPackage($customer->websites()->create(['domain' => 'klant.nl', 'status' => 'active', 'php_version' => '8.4',
            'ssl_status' => 'active', 'aliases' => ['www.klant.nl']]), $plan);

        return [$user, $site];
    }

    private function lastApply(): array
    {
        return collect($this->agent()->calls)->where('action', 'webrules.apply')->last()['params'];
    }

    public function test_client_adds_and_removes_redirects(): void
    {
        [$user, $site] = $this->client();
        $this->actingAs($user)->get("/web-rules/{$site->id}")->assertOk()->assertSee('Redirects &amp; access', false)->assertSee('Add redirect');

        $this->actingAs($user)->post("/web-rules/{$site->id}/redirects", ['from' => 'https://www.klant.nl/oud/', 'to' => 'nieuw', 'code' => 301, 'keep_query' => '1'])
            ->assertRedirect("/web-rules/{$site->id}?tab=redirects")->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/web-rules/{$site->id}/redirects", ['from' => '/blog', 'to' => 'example.org/news', 'code' => 302, 'prefix' => '1'])
            ->assertSessionHasNoErrors();

        $params = $this->lastApply();
        $this->assertSame('klant.nl', $params['domain']);
        $this->assertSame([
            ['from' => '/oud', 'to' => '/nieuw', 'code' => 301, 'prefix' => false, 'keep_query' => true],
            ['from' => '/blog', 'to' => 'https://example.org/news', 'code' => 302, 'prefix' => true, 'keep_query' => false],
        ], $params['redirects']);

        $this->actingAs($user)->get("/web-rules/{$site->id}?tab=redirects")->assertSee('/oud')->assertSee('https://example.org/news');
        $id = SiteWebRules::sole()->redirects[0]['id'];
        $this->actingAs($user)->delete("/web-rules/{$site->id}/redirects/{$id}")->assertSessionHasNoErrors();
        $this->assertCount(1, SiteWebRules::sole()->redirects);
        $this->assertDatabaseHas('activity_logs', ['action' => 'web_rules.redirect_removed', 'subject' => 'klant.nl']);
    }

    public function test_bad_redirects_are_refused_before_the_server(): void
    {
        [$user, $site] = $this->client();
        $bad = [
            ['from' => '/a;b', 'to' => '/c'],
            ['from' => 'https://other.nl/x', 'to' => '/c'],
            ['from' => '/a', 'to' => '/c"; return 200'],
            ['from' => '/a', 'to' => 'https://x.nl/$host'],
            ['from' => '/a', 'to' => '//evil.nl'],
            ['from' => '/a', 'to' => '/a'],
            ['from' => '/', 'to' => '/x', 'prefix' => '1'],
        ];
        foreach ($bad as $form) {
            $this->actingAs($user)->post("/web-rules/{$site->id}/redirects", $form + ['code' => 301])->assertSessionHasErrors();
        }
        $this->actingAs($user)->post("/web-rules/{$site->id}/redirects", ['from' => '/a', 'to' => '/b', 'code' => 200])->assertSessionHasErrors('code');
        $this->assertSame(0, SiteWebRules::count());
        $this->assertNull(collect($this->agent()->calls)->firstWhere('action', 'webrules.apply'));
    }

    public function test_a_refused_change_is_not_saved(): void
    {
        [$user, $site] = $this->client();
        $this->agent()->respond('webrules.apply', new AgentException('conflict', 'Nginx refused these rules, nothing was changed'));
        $this->actingAs($user)->post("/web-rules/{$site->id}/redirects", ['from' => '/a', 'to' => '/b', 'code' => 301])
            ->assertSessionHasErrors('agent');
        $this->assertSame(0, SiteWebRules::count());
    }

    public function test_protected_folders_with_users(): void
    {
        [$user, $site] = $this->client();
        $this->actingAs($user)->post("/web-rules/{$site->id}/folders", ['path' => '/admin/', 'realm' => 'Beheer', 'username' => 'piet', 'password' => 'een-lang-wachtwoord'])
            ->assertSessionHasNoErrors();
        $folder = SiteWebRules::sole()->folders[0];
        $this->assertSame(['/admin', 'Beheer', 'piet'], [$folder['path'], $folder['realm'], $folder['users'][0]['name']]);
        $this->assertStringStartsWith('$6$', $folder['users'][0]['hash']);
        $this->assertSame($folder['users'][0]['hash'], crypt('een-lang-wachtwoord', $folder['users'][0]['hash']));
        $this->assertStringNotContainsString('een-lang-wachtwoord', json_encode($this->agent()->calls));

        $this->actingAs($user)->post("/web-rules/{$site->id}/folders/{$folder['id']}/users", ['username' => 'klaas', 'password' => 'nog-een-wachtwoord'])->assertSessionHasNoErrors();
        $this->assertSame(['klaas', 'piet'], array_column($this->lastApply()['protected'][0]['users'], 'name'));

        // Same name again: a new password, not a second user.
        $this->actingAs($user)->post("/web-rules/{$site->id}/folders/{$folder['id']}/users", ['username' => 'piet', 'password' => 'ander-wachtwoord'])->assertSessionHasNoErrors();
        $this->assertCount(2, SiteWebRules::sole()->folders[0]['users']);

        $this->actingAs($user)->delete("/web-rules/{$site->id}/folders/{$folder['id']}/users", ['username' => 'klaas'])->assertSessionHasNoErrors();
        $this->actingAs($user)->delete("/web-rules/{$site->id}/folders/{$folder['id']}/users", ['username' => 'piet'])->assertSessionHasErrors('folders');
        $this->assertCount(1, SiteWebRules::sole()->folders[0]['users']);

        $this->actingAs($user)->post("/web-rules/{$site->id}/folders", ['path' => '/admin', 'username' => 'x', 'password' => 'een-lang-wachtwoord'])->assertSessionHasErrors('path');
        $this->actingAs($user)->post("/web-rules/{$site->id}/folders", ['path' => '/b', 'realm' => 'x"y', 'username' => 'x', 'password' => 'een-lang-wachtwoord'])->assertSessionHasErrors('realm');
        $this->actingAs($user)->post("/web-rules/{$site->id}/folders", ['path' => '/b', 'username' => 'a:b', 'password' => 'een-lang-wachtwoord'])->assertSessionHasErrors('username');

        $this->actingAs($user)->delete("/web-rules/{$site->id}/folders/{$folder['id']}")->assertSessionHasNoErrors();
        $this->assertSame([], $this->lastApply()['protected']);
    }

    public function test_error_pages_https_and_access(): void
    {
        [$user, $site] = $this->client();
        $this->actingAs($user)->put("/web-rules/{$site->id}/error-pages", ['pages' => ['404' => 'errors/404.html', '503' => '/onderhoud.html', '500' => '']])
            ->assertSessionHasNoErrors();
        $this->assertEquals(['404' => '/errors/404.html', '503' => '/onderhoud.html'], (array) $this->lastApply()['error_pages']);
        $this->actingAs($user)->put("/web-rules/{$site->id}/error-pages", ['pages' => ['404' => '/']])->assertSessionHasErrors('pages.404');

        $this->actingAs($user)->put("/web-rules/{$site->id}/https", ['force_https' => '0', 'hsts_enabled' => '1', 'hsts_max_age' => 63072000, 'hsts_subdomains' => '1', 'hsts_preload' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame([false, ['enabled' => true, 'max_age' => 63072000, 'subdomains' => true, 'preload' => true]],
            [$this->lastApply()['force_https'], $this->lastApply()['hsts']]);
        $this->actingAs($user)->put("/web-rules/{$site->id}/https", ['force_https' => '1', 'hsts_enabled' => '1', 'hsts_max_age' => 86400, 'hsts_preload' => '1'])
            ->assertSessionHasErrors('hsts_preload');

        $this->actingAs($user)->put("/web-rules/{$site->id}/access", ['access_mode' => 'deny', 'networks' => "203.0.113.7  # scraper\n198.51.100.0/24\n", 'countries_mode' => 'deny', 'countries' => ['RU', 'CN']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['mode' => 'deny', 'networks' => ['203.0.113.7', '198.51.100.0/24'], 'countries_mode' => 'deny', 'countries' => ['CN', 'RU']], $this->lastApply()['access']);
        $this->assertSame('scraper', SiteWebRules::sole()->access_networks[0]['comment']);

        $this->actingAs($user)->put("/web-rules/{$site->id}/access", ['access_mode' => 'allow', 'networks' => '203.0.113.7', 'countries_mode' => 'off'])
            ->assertSessionHas('warning');
        $this->actingAs($user)->put("/web-rules/{$site->id}/access", ['access_mode' => 'deny', 'networks' => 'no-ip', 'countries_mode' => 'off'])->assertSessionHasErrors('networks');
        $this->actingAs($user)->put("/web-rules/{$site->id}/access", ['access_mode' => 'off', 'countries_mode' => 'deny', 'countries' => ['XX']])->assertSessionHasErrors();

        $this->agent()->respond('webrules.status', ['geoip' => ['available' => false]]);
        $this->actingAs($user)->put("/web-rules/{$site->id}/access", ['access_mode' => 'off', 'countries_mode' => 'deny', 'countries' => ['CN']])->assertSessionHasErrors('countries');
        $this->actingAs($user)->get("/web-rules/{$site->id}?tab=access")->assertOk()->assertSee('the country database is missing');

        foreach (['https', 'errors', 'folders'] as $tab) {
            $this->actingAs($user)->get("/web-rules/{$site->id}?tab={$tab}")->assertOk();
        }
    }

    public function test_other_customers_and_switched_off_feature(): void
    {
        [, $site] = $this->client();
        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'Piet', 'email' => 'piet@x.nl'])->id]);
        $this->actingAs($other)->get("/web-rules/{$site->id}")->assertNotFound();
        $this->actingAs($other)->post("/web-rules/{$site->id}/redirects", ['from' => '/a', 'to' => '/b', 'code' => 301])->assertNotFound();

        [$user] = [User::where('customer_id', $site->customer_id)->first()];
        config(['web-rules.customer_access' => false]);
        $this->actingAs($user)->get("/web-rules/{$site->id}")->assertForbidden();
        $this->actingAs($user)->get("/websites/{$site->id}")->assertOk()->assertDontSee(route('client.web-rules.show', [$site, 'tab' => 'redirects']), false);
    }

    public function test_domain_page_tiles_and_admin_twin(): void
    {
        [$user, $site] = $this->client();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=tools")->assertOk()
            ->assertSee('Redirects &amp; error pages', false)->assertSee('Access &amp; passwords', false)
            ->assertSee(route('client.web-rules.show', [$site, 'tab' => 'redirects']), false);

        $this->actingAsAdmin();
        $this->get("/admin/websites/{$site->id}?tab=tools")->assertOk()->assertSee(route('admin.web-rules.show', [$site, 'tab' => 'redirects']), false);
        $this->get("/admin/web-rules/{$site->id}")->assertOk()->assertSee('klant.nl');
        $this->post("/admin/web-rules/{$site->id}/redirects", ['from' => '/a', 'to' => '/b', 'code' => 308])
            ->assertRedirect("/admin/web-rules/{$site->id}?tab=redirects")->assertSessionHasNoErrors();
        $this->assertSame(308, SiteWebRules::sole()->redirects[0]['code']);
    }

    public function test_dutch_translation(): void
    {
        app()->setLocale('nl');
        $this->assertSame('Doorverwijzingen & toegang', __('Redirects & access'));
    }

    public function test_network_helpers(): void
    {
        $this->assertTrue(WebRulesController::validNetwork('2001:db8::/48'));
        $this->assertFalse(WebRulesController::validNetwork('1.2.3.4/33'));
        $this->assertTrue(WebRulesController::inNetwork('198.51.100.77', '198.51.100.0/24'));
        $this->assertFalse(WebRulesController::inNetwork('198.51.101.1', '198.51.100.0/24'));
        $this->assertTrue(WebRulesController::inNetwork('2001:db8::1', '2001:db8::/32'));
        $this->assertTrue(WebRulesController::inNetwork('10.0.0.1', '10.0.0.1'));
    }
}
