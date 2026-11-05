<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_admin_with_two_factor_must_enter_code(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'secret-Password-1']);

        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-Password-1'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->get('/admin')->assertRedirect(route('two-factor.challenge'));

        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $code = app(Totp::class)->code('JBSWY3DPEHPK3PXP');
        $this->post('/two-factor', ['code' => $code])->assertRedirect();
        $this->get('/admin')->assertOk();
    }

    public function test_admin_without_two_factor_must_set_it_up(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin')->assertRedirect(route('profile.security'));
        $this->actingAs($admin)->get(route('profile.security'))->assertOk();
    }

    public function test_two_factor_setup_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('profile.two-factor.enable'))->assertRedirect();
        $secret = session('two_factor_pending');
        $this->assertNotEmpty($secret);

        $this->post(route('profile.two-factor.confirm'), ['code' => app(Totp::class)->code($secret)])->assertRedirect();
        $this->assertTrue($admin->fresh()->hasTwoFactor());
        $this->get('/admin')->assertOk();
    }

    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_suspended_customer_cannot_use_the_panel(): void
    {
        $customer = Customer::create(['name' => 'X', 'email' => 'x@example.test', 'status' => 'suspended']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_client_cannot_open_admin(): void
    {
        $customer = Customer::create(['name' => 'X', 'email' => 'x@example.test']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
