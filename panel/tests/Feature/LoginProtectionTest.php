<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Security\FailedLoginLog;
use Rhis\Security\LoginProtection;
use Rhis\Security\Models\SiteProtection;
use Tests\TestCase;

class LoginProtectionTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/whp-auth-'.bin2hex(random_bytes(4)).'.log';
        config(['security.auth_log' => $this->log]);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function applied(): array
    {
        $calls = collect($this->agent()->calls)->where('action', 'security.apply')->values();
        $this->assertNotEmpty($calls, 'security.apply was not called');

        return $calls->last()['params'];
    }

    private function services(array $overrides = []): array
    {
        $out = [];
        foreach (LoginProtection::DEFAULTS as $name => $d) {
            $out[$name] = array_merge($d, ['enabled' => '1'], $overrides[$name] ?? []);
        }

        return $out;
    }

    public function test_admin_page_renders_defaults_and_is_in_the_menu(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/security/protection')->assertOk()
            ->assertSee('WordPress')->assertSee('name="services[ssh][temp_after]"', false)
            ->assertSee(route('admin.protection.allowlist.store'), false);
        $this->get('/admin')->assertSee(route('admin.protection.edit'), false);
        $this->get('/admin/security')->assertOk()->assertSee(route('admin.protection.edit'), false);
    }

    public function test_admin_saves_thresholds_and_pushes_them(): void
    {
        $this->actingAsAdmin();

        $this->put('/admin/security/protection', [
            'services' => $this->services([
                'ssh' => ['temp_after' => 3, 'window_minutes' => 5, 'ban_minutes' => 120, 'perm_after' => 12, 'perm_window_hours' => 48],
                'mail' => ['enabled' => '0'],
            ]),
            'permanent_days' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $params = $this->applied();
        $this->assertSame(['enabled' => true, 'temp_after' => 3, 'window_minutes' => 5, 'ban_minutes' => 120, 'perm_after' => 12, 'perm_window_hours' => 48], $params['services']['ssh']);
        $this->assertFalse($params['services']['mail']['enabled']);
        $this->assertSame(0, $params['permanent_days']);
        $this->assertSame(3, app(LoginProtection::class)->services()['ssh']['temp_after']);
        $this->assertTrue(ActivityLog::where('action', 'security.protection_updated')->exists());
    }

    public function test_permanent_threshold_must_be_above_the_temporary_one(): void
    {
        $this->actingAsAdmin();

        $this->put('/admin/security/protection', [
            'services' => $this->services(['panel' => ['temp_after' => 10, 'perm_after' => 5]]),
            'permanent_days' => 0,
        ])->assertSessionHasErrors('services.panel.perm_after');
        $this->put('/admin/security/protection', ['services' => $this->services(['ssh' => ['temp_after' => 0]]), 'permanent_days' => 0])
            ->assertSessionHasErrors('services.ssh.temp_after');
        $this->assertEmpty(collect($this->agent()->calls)->where('action', 'security.apply'));
    }

    public function test_admin_allowlist(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/security/protection/allowlist', ['address' => '203.0.113.9/32', 'comment' => 'Office'])->assertSessionHasNoErrors();
        $this->assertSame([['address' => '203.0.113.9', 'comment' => 'Office']], $this->applied()['allowlist']);
        $this->post('/admin/security/protection/allowlist', ['address' => '203.0.113.9'])->assertSessionHasErrors('address');
        $this->post('/admin/security/protection/allowlist', ['address' => '10.0.0.0/8'])->assertSessionHasErrors('address');
        $this->post('/admin/security/protection/allowlist', ['address' => 'nope'])->assertSessionHasErrors('address');

        $this->delete('/admin/security/protection/allowlist', ['address' => '203.0.113.9'])->assertRedirect();
        $this->assertSame([], $this->applied()['allowlist']);
    }

    public function test_agent_failure_keeps_the_settings_and_says_so(): void
    {
        $this->actingAsAdmin();
        $this->agent()->respond('security.apply', new AgentException('command_failed', 'CrowdSec rejected the new configuration'));

        $this->put('/admin/security/protection', ['services' => $this->services(['ssh' => ['temp_after' => 7]]), 'permanent_days' => 30])
            ->assertSessionHasErrors('agent');
        $this->assertSame(7, app(LoginProtection::class)->services()['ssh']['temp_after']);
        $this->assertFalse(app(LoginProtection::class)->lastApply()['ok']);
        $this->get('/admin/security/protection')->assertSee('CrowdSec rejected the new configuration');
    }

    public function test_customer_switches_protection_for_own_site_only(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $other = Customer::create(['name' => 'Other', 'email' => 'other@example.test']);
        $site = Website::create(['customer_id' => $mine->id, 'domain' => 'shop.nl', 'status' => 'active', 'profile' => 'wordpress']);
        $otherSite = Website::create(['customer_id' => $other->id, 'domain' => 'other.nl', 'status' => 'active']);
        $user = User::factory()->create(['customer_id' => $mine->id]);
        $this->actingAs($user);

        $this->get('/security')->assertOk()->assertSee('shop.nl')->assertDontSee('other.nl');
        $this->get("/security/{$site->id}")->assertOk()->assertSee('WordPress password guessing')->assertSee('203.0.113.7');
        $this->get("/websites/{$site->id}")->assertSee(route('client.protection.show', $site), false);
        $this->get("/security/{$otherSite->id}")->assertNotFound();
        $this->put("/security/{$otherSite->id}", ['enabled' => '0', 'wordpress' => '0'])->assertNotFound();

        $this->put("/security/{$site->id}", ['enabled' => '1', 'wordpress' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([['domain' => 'shop.nl', 'enabled' => true, 'wordpress' => false, 'allowlist' => []]], $this->applied()['sites']);
        $this->assertFalse(SiteProtection::wordpressProtected($site));
        $this->assertTrue(SiteProtection::wordpressProtected($otherSite));

        $this->put("/security/{$site->id}", ['enabled' => '1', 'wordpress' => '1'])->assertRedirect();
        $this->assertSame([], $this->applied()['sites'], 'a fully protected site is not sent');
    }

    public function test_customer_allowlist_releases_blocks_of_the_site(): void
    {
        $customer = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'shop.nl', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['customer_id' => $customer->id]));

        $this->post("/security/{$site->id}/allowlist", ['address' => '198.51.100.4', 'comment' => 'Home'])->assertSessionHasNoErrors();
        $this->assertSame(['198.51.100.4'], $this->applied()['sites'][0]['allowlist']);
        $release = collect($this->agent()->calls)->firstWhere('action', 'security.site_release');
        $this->assertSame(['domain' => 'shop.nl', 'address' => '198.51.100.4'], $release['params']);

        $this->post("/security/{$site->id}/allowlist", ['address' => '198.51.100.0/16'])->assertSessionHasErrors('address');
        $this->post("/security/{$site->id}/allowlist", ['address' => '198.51.100.4'])->assertSessionHasErrors('address');

        config(['security.client_allowlist_max' => 1]);
        $this->post("/security/{$site->id}/allowlist", ['address' => '198.51.100.5'])->assertSessionHasErrors('address');

        $this->delete("/security/{$site->id}/allowlist", ['address' => '198.51.100.4'])->assertRedirect();
        $this->assertNull(SiteProtection::where('website_id', $site->id)->first()->allowlist);
    }

    public function test_failed_logins_are_written_for_crowdsec(): void
    {
        $user = User::factory()->create(['email' => 'jan@example.test']);

        $this->post('/login', ['email' => 'jan@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => "evil\nuser=x@example.test", 'password' => 'wrong']);

        $lines = file($this->log, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines, 'invalid input is rejected by validation before an attempt');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d \S+ whp-panel\[\d+\]: auth_failed ip=127\.0\.0\.1 user=jan@example\.test$/', $lines[0]);
    }

    public function test_log_line_cannot_be_forged(): void
    {
        $line = FailedLoginLog::line('1.2.3.4', "a b\nauth_failed ip=9.9.9.9 user=x");
        $this->assertStringEndsWith("auth_failed ip=1.2.3.4 user=a_b_auth_failed_ip_9.9.9.9_user_x\n", $line);
        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertStringContainsString('ip=0.0.0.0', FailedLoginLog::line('not-an-ip', 'u'));
    }

    public function test_artisan_command_applies(): void
    {
        $this->artisan('whp:security:apply')->assertSuccessful();
        $this->assertSame(['ssh', 'mail', 'panel', 'wordpress'], array_keys($this->applied()['services']));

        $this->agent()->respond('security.apply', AgentException::unreachable('Agent down'));
        $this->artisan('whp:security:apply')->assertFailed();
    }

    public function test_networks(): void
    {
        $this->assertTrue(LoginProtection::validNetwork('203.0.113.7'));
        $this->assertTrue(LoginProtection::validNetwork('2001:db8::/48'));
        $this->assertFalse(LoginProtection::validNetwork('2001:db8::/32'));
        $this->assertFalse(LoginProtection::validNetwork('1.2.3.4/33'));
        $this->assertFalse(LoginProtection::validNetwork('1.2.3.4/x'));
        $this->assertSame('2001:db8::1', LoginProtection::normalise('2001:DB8:0::1/128'));
        $this->assertSame('203.0.113.0/24', LoginProtection::normalise(' 203.0.113.0/24 '));
    }
}
