<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Security\LoginProtection;
use Tests\TestCase;

class TrustedIpTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function trust(string $address): void
    {
        app(LoginProtection::class)->saveAllowlist([['address' => $address, 'comment' => 'Office']]);
    }

    private function login(User $user, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_admin_from_a_trusted_address_skips_the_code_and_it_is_logged(): void
    {
        $this->trust('203.0.113.0/24');
        $admin = User::factory()->admin()->create();

        $this->login($admin, '203.0.113.20')->assertRedirect(route('home'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/admin')->assertOk();

        $log = ActivityLog::where('action', 'auth.two_factor_skipped')->sole();
        $this->assertSame('203.0.113.20', $log->ip);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Two-factor code skipped (trusted IP address)', $log->label());
    }

    public function test_other_addresses_still_need_the_code(): void
    {
        $this->trust('203.0.113.0/24');
        $admin = User::factory()->admin()->create();

        $this->login($admin, '198.51.100.7')->assertRedirect(route('two-factor.challenge'));
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/admin')->assertRedirect(route('two-factor.challenge'));
        $this->assertFalse(ActivityLog::where('action', 'auth.two_factor_skipped')->exists());
    }

    public function test_session_moving_to_another_address_asks_for_the_code(): void
    {
        $this->trust('203.0.113.20');
        $admin = User::factory()->admin()->create();

        $this->login($admin, '203.0.113.20');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/admin')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_removing_the_address_ends_the_skip(): void
    {
        $this->trust('203.0.113.20');
        $admin = User::factory()->admin()->create();
        $this->login($admin, '203.0.113.20');

        app(LoginProtection::class)->saveAllowlist([]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/admin')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_customers_are_not_affected(): void
    {
        $this->trust('203.0.113.0/24');
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);

        $this->login($user, '203.0.113.20')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_admin_page_shows_the_trusted_list_in_dutch(): void
    {
        $this->trust('203.0.113.0/24');
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['locale' => 'nl'])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/admin/security/protection')->assertOk()
            ->assertSee('Vertrouwde IP-adressen')->assertSee('203.0.113.0/24')->assertSee('jij');
    }
}
