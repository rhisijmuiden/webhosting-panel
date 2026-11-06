<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\QuickController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Traffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuickViewTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $c = Customer::create(['company' => 'Bakkerij de Vries', 'name' => 'Jan', 'email' => 'jan@bakkerij.example', 'phone' => '+31 6 1234 5678', 'crm_id' => 'CRM-1001']);
        Website::create(['customer_id' => $c->id, 'domain' => 'bakkerij.example', 'status' => 'active']);

        return $c;
    }

    public function test_quick_view_renders_with_tab_bar_and_attention_list(): void
    {
        $this->actingAsAdmin();
        $c = $this->customer();
        Website::create(['customer_id' => $c->id, 'domain' => 'kapot.example', 'status' => 'failed', 'last_error' => 'Provisioning failed']);

        $this->get('/admin/quick')->assertOk()
            ->assertSee('Quick view')
            ->assertSee('kapot.example')
            ->assertSee('class="tabbar"', false)
            ->assertSee('tab tab-main', false);
    }

    public function test_quick_view_is_admin_only(): void
    {
        $c = $this->customer();
        $client = User::factory()->create(['customer_id' => $c->id]);
        $this->actingAs($client)->withSession(['two_factor_passed' => true]);

        $this->get('/admin/quick')->assertForbidden();
        $this->getJson('/admin/quick/search?q=bak')->assertForbidden();
        $this->post("/admin/customers/{$c->id}/suspend")->assertForbidden();
    }

    public function test_search_finds_customers_and_domains(): void
    {
        $this->actingAsAdmin();
        $this->customer();

        $this->getJson('/admin/quick/search?q=b')->assertOk()->assertExactJson(['results' => []]);
        $this->getJson('/admin/quick/search?q=bakkerij')->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.type', 'customer')
            ->assertJsonPath('results.0.title', 'Bakkerij de Vries')
            ->assertJsonPath('results.1.type', 'website')
            ->assertJsonPath('results.1.title', 'bakkerij.example');
        $this->getJson('/admin/quick/search?q=CRM-1001')->assertJsonPath('results.0.title', 'Bakkerij de Vries');
        // LIKE wildcards are plain text.
        $this->getJson('/admin/quick/search?q=%25%25')->assertJsonCount(0, 'results');
    }

    public function test_suspend_and_reactivate_a_customer(): void
    {
        $this->actingAsAdmin();
        $c = $this->customer();
        // A site already suspended for the traffic limit stays as it is.
        $traffic = Website::create(['customer_id' => $c->id, 'domain' => 'traffic.example', 'status' => 'suspended', 'last_error' => Traffic::SUSPENDED]);

        $this->get("/admin/customers/{$c->id}")->assertOk()->assertSee('Suspend')->assertSee('tel:+31612345678', false);
        $this->post("/admin/customers/{$c->id}/suspend")->assertRedirect()->assertSessionHas('status');

        $this->assertSame('suspended', $c->fresh()->status);
        $site = Website::where('domain', 'bakkerij.example')->first();
        $this->assertSame('suspended', $site->status);
        $this->assertSame(QuickController::SUSPENDED, $site->last_error);
        $this->assertSame([['domain' => 'bakkerij.example']], $this->calls('site.suspend')->pluck('params')->all());
        $this->assertTrue(ActivityLog::where('action', 'customer.suspended')->exists());

        $this->get("/admin/customers/{$c->id}")->assertSee('Reactivate');
        $this->post("/admin/customers/{$c->id}/resume")->assertRedirect();
        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame('active', $site->fresh()->status);
        $this->assertSame('suspended', $traffic->fresh()->status);
        $this->assertSame([['domain' => 'bakkerij.example']], $this->calls('site.unsuspend')->pluck('params')->all());
    }

    public function test_new_password_and_two_factor_reset_for_a_login(): void
    {
        $this->actingAsAdmin();
        $c = $this->customer();
        $login = User::factory()->create(['customer_id' => $c->id, 'email' => 'jan@login.example', 'password' => 'old-password-123']);
        $login->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->get("/admin/customers/{$c->id}")->assertSee('Turn off 2FA');

        $response = $this->post("/admin/customers/{$c->id}/users/{$login->id}/password")->assertRedirect();
        $password = $response->getSession()->get('new_password')['password'];
        $this->assertSame(16, strlen($password));
        $this->assertTrue(Hash::check($password, $login->fresh()->password));
        $this->assertTrue(ActivityLog::where('action', 'customer.user_password_reset')->exists());

        $this->post("/admin/customers/{$c->id}/users/{$login->id}/two-factor")->assertRedirect();
        $this->assertFalse($login->fresh()->hasTwoFactor());

        // A login of another customer is not reachable through this customer.
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@example.test']);
        $this->post("/admin/customers/{$other->id}/users/{$login->id}/password")->assertNotFound();
    }

    public function test_generated_passwords_have_no_look_alike_characters(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^[a-km-np-zA-HJ-NP-Z2-9]{16}$/', QuickController::generatePassword());
        }
    }

    public function test_client_panel_has_a_tab_bar_too(): void
    {
        $c = $this->customer();
        $client = User::factory()->create(['customer_id' => $c->id]);
        $this->actingAs($client)->withSession(['two_factor_passed' => true]);

        $this->get('/')->assertRedirect();
        $this->followingRedirects()->get('/')->assertOk()->assertSee('class="tabbar"', false)->assertDontSee('tab tab-main', false);
    }

    private function calls(string $action)
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }
}
