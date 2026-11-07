<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\PasswordChanger;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerPasswordsTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $customer = [], array $user = []): User
    {
        $c = Customer::create($customer + ['name' => 'Jan', 'email' => 'jan@bakkerij.example']);

        return User::factory()->create($user + ['customer_id' => $c->id, 'email' => 'jan@bakkerij.example', 'locale' => 'nl']);
    }

    public function test_customer_page_offers_the_password_menu(): void
    {
        $this->actingAsAdmin();
        $u = $this->login();

        $this->get("/admin/customers/{$u->customer_id}")->assertOk()
            ->assertSee('Set a password…')
            ->assertSee('Mail a reset link')
            ->assertSee('Generate a password')
            ->assertSee(route('admin.customers.users.password.set', [$u->customer_id, $u->id]), false);
    }

    public function test_admin_sets_a_chosen_password(): void
    {
        $this->actingAsAdmin();
        $u = $this->login();

        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/set", ['password' => 'kort', 'password_confirmation' => 'kort'])
            ->assertSessionHasErrors('password', null, 'password_set');

        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/set", [
            'password' => 'Nieuw-wachtwoord-2026', 'password_confirmation' => 'Nieuw-wachtwoord-2026',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertTrue(Hash::check('Nieuw-wachtwoord-2026', $u->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.user_password_set', 'subject' => $u->email, 'customer_id' => $u->customer_id]);
    }

    public function test_a_new_password_logs_the_login_out_everywhere(): void
    {
        $u = $this->login();
        $remember = $u->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'phone', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-user', 'user_id' => null, 'payload' => '', 'last_activity' => time()],
        ]);
        config(['session.driver' => 'database']);

        app(PasswordChanger::class)->change($u, 'Nieuw-wachtwoord-2026');

        $this->assertDatabaseMissing('sessions', ['id' => 'phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user']);
        $this->assertNotSame($remember, $u->fresh()->remember_token);
    }

    public function test_admin_cannot_touch_a_login_of_another_customer(): void
    {
        $this->actingAsAdmin();
        $u = $this->login();
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@example.test']);

        $this->post("/admin/customers/{$other->id}/users/{$u->id}/password/set", ['password' => 'Nieuw-wachtwoord-2026', 'password_confirmation' => 'Nieuw-wachtwoord-2026'])->assertNotFound();
        $this->post("/admin/customers/{$other->id}/users/{$u->id}/password/link")->assertNotFound();
    }

    public function test_clients_cannot_use_the_admin_password_actions(): void
    {
        $u = $this->login();
        $this->actingAs($u)->withSession(['two_factor_passed' => true]);

        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/link")->assertForbidden();
    }

    public function test_admin_mails_a_reset_link_in_the_customers_language(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $u = $this->login();

        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/link")->assertRedirect()->assertSessionHas('status');
        // Again right away: an administrator is not throttled.
        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/link")->assertSessionHasNoErrors();

        Notification::assertSentTo($u, ResetPassword::class, function (ResetPassword $n, array $channels, User $notifiable, ?string $locale) use ($u) {
            app()->setLocale($locale ?? 'en');
            $mail = $n->toMail($notifiable);

            return $locale === 'nl' && str_contains($mail->subject, 'Stel je wachtwoord opnieuw in')
                && str_contains($mail->viewData['url'], '/reset-password/') && str_contains($mail->viewData['url'], urlencode($u->email));
        });
        $this->assertSame(2, ActivityLog::where('action', 'customer.user_reset_link_sent')->count());
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get('/forgot-password')->assertOk()->assertSee('Send reset link');
    }

    public function test_forgot_password_does_not_tell_who_has_a_login(): void
    {
        Notification::fake();
        $u = $this->login();

        $known = $this->post('/forgot-password', ['email' => 'JAN@bakkerij.example'])->assertRedirect()->assertSessionHas('status')->getSession()->get('status');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect()->assertSessionHas('status')->getSession()->get('status');

        $this->assertSame(str_replace('jan@bakkerij.example', 'X', $known), str_replace('nobody@example.test', 'X', $unknown));
        Notification::assertSentTo($u, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.password_reset_requested', 'customer_id' => $u->customer_id]);
    }

    public function test_no_link_for_suspended_customers_or_inactive_logins(): void
    {
        Notification::fake();
        $this->login(['status' => 'suspended']);
        $this->post('/forgot-password', ['email' => 'jan@bakkerij.example'])->assertSessionHas('status');

        User::factory()->create(['email' => 'uit@example.test', 'is_active' => false]);
        $this->post('/forgot-password', ['email' => 'uit@example.test'])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_customer_resets_the_password_with_the_link(): void
    {
        Notification::fake();
        $u = $this->login();
        $this->post('/forgot-password', ['email' => $u->email]);

        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get("/reset-password/{$token}?email=".urlencode($u->email))->assertOk()->assertSee('Choose a new password');

        $this->post('/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'zwak', 'password_confirmation' => 'zwak'])
            ->assertSessionHasErrors('password');

        $this->post('/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'Sterk-Wachtwoord-2026', 'password_confirmation' => 'Sterk-Wachtwoord-2026'])
            ->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('Sterk-Wachtwoord-2026', $u->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.password_reset', 'subject' => $u->email, 'customer_id' => $u->customer_id]);

        // The link works only once.
        $this->post('/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'Ander-Wachtwoord-2026', 'password_confirmation' => 'Ander-Wachtwoord-2026'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Sterk-Wachtwoord-2026', $u->fresh()->password));
    }

    public function test_a_wrong_token_does_not_change_the_password(): void
    {
        $u = $this->login();
        $before = $u->password;

        $this->post('/reset-password', ['token' => str_repeat('a', 64), 'email' => $u->email, 'password' => 'Sterk-Wachtwoord-2026', 'password_confirmation' => 'Sterk-Wachtwoord-2026'])
            ->assertSessionHasErrors('email');
        $this->assertSame($before, $u->fresh()->password);
    }

    public function test_the_admin_set_password_also_cancels_open_reset_links(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $u = $this->login();
        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/link");
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $u->email]);

        $this->post("/admin/customers/{$u->customer_id}/users/{$u->id}/password/set", ['password' => 'Nieuw-wachtwoord-2026', 'password_confirmation' => 'Nieuw-wachtwoord-2026']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $u->email]);
    }
}
