<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Waf\Models\WafEvent;
use Rhis\Waf\Models\WafSite;
use Rhis\Waf\Waf;
use Tests\TestCase;

class WebApplicationFirewallTest extends TestCase
{
    use RefreshDatabase;

    private function applied(): array
    {
        $calls = collect($this->agent()->calls)->where('action', 'waf.apply')->values();
        $this->assertNotEmpty($calls, 'waf.apply was not called');

        return $calls->last()['params'];
    }

    private function site(string $domain = 'shop.nl', ?Customer $customer = null): Website
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'klant-'.$domain.'@example.test']);

        return Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active']);
    }

    private function event(Website $site, array $overrides = []): WafEvent
    {
        return WafEvent::create(array_merge([
            'website_id' => $site->id, 'domain' => $site->domain, 'request_id' => bin2hex(random_bytes(8)),
            'occurred_at' => now()->subMinutes(5), 'ip' => '203.0.113.7', 'method' => 'GET', 'uri' => '/zoeken?q=1%27+or+1=1',
            'host' => $site->domain, 'status' => 403, 'blocked' => true, 'mode' => 'block', 'score' => 5,
            'rules' => [['id' => 942100, 'message' => 'SQL Injection Attack Detected via libinjection', 'data' => "Matched Data: 1' or 1=1", 'severity' => 2, 'tags' => ['attack-sqli']]],
            'rule_ids' => ',942100,',
        ], $overrides));
    }

    public function test_admin_pages_render_and_are_in_the_tools(): void
    {
        $this->actingAsAdmin();
        $site = $this->site();
        $event = $this->event($site);

        $this->get('/admin/web-firewall')->assertOk()->assertSee('shop.nl')->assertSee('SQL injection')->assertSee('942100')
            ->assertSee('detect-only mode'); // default for new servers
        $this->get('/admin/web-firewall/sites')->assertOk()->assertSee('shop.nl')->assertSee('Server default');
        $this->get("/admin/web-firewall/sites/{$site->id}")->assertOk()->assertSee('Exceptions');
        $this->get("/admin/web-firewall/events/{$event->id}")->assertOk()->assertSee('This was not an attack')->assertSee('/zoeken');
        $this->get('/admin/web-firewall/settings')->assertOk()->assertSee('name="paranoia"', false);
        $this->get('/admin/tools')->assertSee(route('admin.waf.index'), false);
        $this->get('/admin/security-overview')->assertOk()->assertSee(route('admin.waf.index'), false);
    }

    public function test_dutch_translation(): void
    {
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));

        $this->get('/admin/web-firewall/settings')->assertOk()->assertSee('Webapplicatie-firewall');
    }

    public function test_payload_uses_default_mode_and_site_choices(): void
    {
        $a = $this->site('a.nl');
        $b = $this->site('b.nl');
        WafSite::create(['website_id' => $b->id, 'mode' => 'block', 'exceptions' => [Waf::exception(942100, '/wp-admin/', null, 'editor')]]);

        $payload = app(Waf::class)->payload();
        $this->assertSame(1, $payload['paranoia']);
        $this->assertSame(5, $payload['threshold']);
        $this->assertSame([
            ['domain' => 'a.nl', 'mode' => 'detect', 'exceptions' => []],
            ['domain' => 'b.nl', 'mode' => 'block', 'exceptions' => [['rule' => 942100, 'path' => '/wp-admin/', 'param' => null]]],
        ], $payload['sites']);
    }

    public function test_admin_changes_settings_and_site_mode(): void
    {
        $this->actingAsAdmin();
        $site = $this->site();

        $this->put('/admin/web-firewall/settings', ['default_mode' => 'block', 'paranoia' => 2, 'threshold' => 7, 'client_control' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = $this->applied();
        $this->assertSame([2, 7, 'block'], [$p['paranoia'], $p['threshold'], $p['sites'][0]['mode']]);
        $this->assertFalse(app(Waf::class)->clientControl());

        $this->put("/admin/web-firewall/sites/{$site->id}", ['mode' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame('off', $this->applied()['sites'][0]['mode']);
        $this->put("/admin/web-firewall/sites/{$site->id}", ['mode' => 'default'])->assertSessionHasNoErrors();
        $this->assertNull(WafSite::where('website_id', $site->id)->value('mode'));
        $this->assertSame('block', $this->applied()['sites'][0]['mode']);
        $this->put("/admin/web-firewall/sites/{$site->id}", ['mode' => 'maybe'])->assertSessionHasErrors('mode');
        $this->assertTrue(ActivityLog::where('action', 'waf.site_mode')->exists());
    }

    public function test_exceptions_are_validated(): void
    {
        $this->actingAsAdmin();
        $site = $this->site();

        $this->post("/admin/web-firewall/sites/{$site->id}/exceptions", ['rule' => 941100, 'param' => 'message'])->assertSessionHasNoErrors();
        $this->assertSame([['rule' => 941100, 'path' => null, 'param' => 'message']], $this->applied()['sites'][0]['exceptions']);
        $this->post("/admin/web-firewall/sites/{$site->id}/exceptions", ['rule' => 941100, 'path' => '/x" "id:1,deny'])->assertSessionHasErrors('path');
        $this->post("/admin/web-firewall/sites/{$site->id}/exceptions", ['rule' => 'abc'])->assertSessionHasErrors('rule');
        $this->post("/admin/web-firewall/sites/{$site->id}/exceptions", ['rule' => 1, 'param' => 'a;b'])->assertSessionHasErrors('param');

        $this->delete("/admin/web-firewall/sites/{$site->id}/exceptions/0")->assertSessionHasNoErrors();
        $this->assertSame([], $this->applied()['sites'][0]['exceptions']);
        $this->delete("/admin/web-firewall/sites/{$site->id}/exceptions/5")->assertNotFound();

        $this->post('/admin/web-firewall/exceptions', ['rule' => 920350])->assertSessionHasNoErrors();
        $this->assertSame([['rule' => 920350, 'path' => null, 'param' => null]], $this->applied()['exceptions']);
    }

    public function test_allow_from_event_for_one_page(): void
    {
        $this->actingAsAdmin();
        $site = $this->site();
        $event = $this->event($site);

        $this->post("/admin/web-firewall/events/{$event->id}/allow", ['rules' => [942100], 'scope' => 'page'])->assertSessionHasNoErrors();
        $this->assertSame([['rule' => 942100, 'path' => '/zoeken', 'param' => null]], $this->applied()['sites'][0]['exceptions']);
        // Only rules of the event itself.
        $this->post("/admin/web-firewall/events/{$event->id}/allow", ['rules' => [949110], 'scope' => 'site'])->assertSessionHasErrors('rules.0');

        $this->post("/admin/web-firewall/events/{$event->id}/allow", ['rules' => [942100], 'scope' => 'server'])->assertSessionHasNoErrors();
        $this->assertSame([['rule' => 942100, 'path' => null, 'param' => null]], $this->applied()['exceptions']);
    }

    public function test_agent_failure_is_shown(): void
    {
        $this->actingAsAdmin();
        $this->site();
        $this->agent()->respond('waf.apply', new AgentException('command_failed', 'Nginx did not accept the firewall settings'));

        $this->put('/admin/web-firewall/settings', ['default_mode' => 'block', 'paranoia' => 1, 'threshold' => 5, 'client_control' => '1'])
            ->assertSessionHasErrors('agent');
        $this->assertSame('block', app(Waf::class)->defaultMode());
        $this->assertFalse(app(Waf::class)->lastApply()['ok']);
        $this->get('/admin/web-firewall/settings')->assertSee('Nginx did not accept the firewall settings');
    }

    public function test_collect_stores_events_once_and_applies_new_sites(): void
    {
        $site = $this->site('klant.nl');
        $event = ['id' => 'abc', 'domain' => 'klant.nl', 'time' => 'Sat Nov  7 02:10:00 2026', 'ip' => '203.0.113.7', 'method' => 'POST',
            'uri' => '/contact', 'host' => 'www.klant.nl', 'user_agent' => 'curl', 'status' => 403, 'blocked' => true, 'mode' => 'block', 'score' => 10,
            'rules' => [['id' => 941100, 'message' => 'XSS Attack Detected via libinjection', 'data' => '<script>', 'severity' => 2, 'tags' => ['attack-xss']]]];
        $this->agent()->respond('waf.events', fn (array $p) => ['events' => [$event, array_merge($event, ['domain' => 'unknown.nl'])], 'cursor' => ['inode' => 5, 'offset' => 100], 'more' => false]);

        $this->artisan('whp:waf:collect')->assertSuccessful();
        $this->artisan('whp:waf:collect')->assertSuccessful();

        $this->assertSame(2, WafEvent::count());
        $stored = WafEvent::where('domain', 'klant.nl')->first();
        $this->assertSame($site->id, $stored->website_id);
        $this->assertSame(',941100,', $stored->rule_ids);
        $this->assertSame('xss', $stored->kind());
        $this->assertNull(WafEvent::where('domain', 'unknown.nl')->value('website_id'));
        $cursors = collect($this->agent()->calls)->where('action', 'waf.events')->pluck('params.cursor')->values();
        $this->assertNull($cursors[0]);
        $this->assertSame(['inode' => 5, 'offset' => 100], $cursors[1]);
        // Applied once (first run), not again while nothing changed.
        $this->assertCount(1, collect($this->agent()->calls)->where('action', 'waf.apply'));

        $this->site('nieuw.nl');
        $this->artisan('whp:waf:collect')->assertSuccessful();
        $this->assertCount(2, collect($this->agent()->calls)->where('action', 'waf.apply'));
    }

    public function test_old_events_are_pruned(): void
    {
        $site = $this->site();
        $this->event($site, ['occurred_at' => now()->subDays(40)]);
        $this->event($site);

        $this->assertSame(1, app(Waf::class)->prune());
        $this->assertSame(1, WafEvent::count());
    }

    public function test_customer_sees_and_manages_own_sites_only(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $site = $this->site('shop.nl', $mine);
        $other = $this->site('other.nl');
        $event = $this->event($site);
        $otherEvent = $this->event($other);
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->get('/web-firewall')->assertOk()->assertSee('shop.nl')->assertDontSee('other.nl');
        $this->get("/web-firewall/{$site->id}")->assertOk()->assertSee('SQL injection');
        $this->get("/web-firewall/{$other->id}")->assertNotFound();
        $this->get("/web-firewall/{$site->id}/events/{$otherEvent->id}")->assertNotFound();
        $this->put("/web-firewall/{$other->id}", ['mode' => 'off'])->assertNotFound();

        $this->put("/web-firewall/{$site->id}", ['mode' => 'block'])->assertSessionHasNoErrors();
        $this->assertSame('block', collect($this->applied()['sites'])->firstWhere('domain', 'shop.nl')['mode']);
        $this->put("/web-firewall/{$site->id}", ['mode' => 'default'])->assertSessionHasErrors('mode');

        $this->get("/web-firewall/{$site->id}/events/{$event->id}")->assertOk()->assertSee('This was not an attack');
        $this->post("/web-firewall/{$site->id}/events/{$event->id}/allow", ['rules' => [942100], 'scope' => 'site'])->assertSessionHasNoErrors();
        $this->assertSame([['rule' => 942100, 'path' => null, 'param' => null]], collect($this->applied()['sites'])->firstWhere('domain', 'shop.nl')['exceptions']);
        $this->post("/web-firewall/{$site->id}/events/{$event->id}/allow", ['rules' => [942100], 'scope' => 'server'])->assertSessionHasErrors('scope');
    }

    public function test_customer_cannot_change_when_admin_says_no(): void
    {
        app(Waf::class)->saveSettings('block', 1, 5, false);
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $site = $this->site('shop.nl', $mine);
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->get("/web-firewall/{$site->id}")->assertOk()->assertSee('Your hosting provider manages the firewall settings');
        $this->put("/web-firewall/{$site->id}", ['mode' => 'off'])->assertForbidden();
        $this->post("/web-firewall/{$site->id}/exceptions", ['rule' => 942100])->assertForbidden();
    }
}
