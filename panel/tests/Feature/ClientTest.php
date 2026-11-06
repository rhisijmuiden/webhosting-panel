<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_sees_only_own_websites(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $other = Customer::create(['name' => 'Other', 'email' => 'other@example.test']);
        $ownSite = Website::create(['customer_id' => $mine->id, 'domain' => 'mine.nl', 'status' => 'active']);
        $otherSite = Website::create(['customer_id' => $other->id, 'domain' => 'other.nl', 'status' => 'active']);
        $user = User::factory()->create(['customer_id' => $mine->id]);

        $this->actingAs($user)->get('/websites')->assertSee('mine.nl')->assertDontSee('other.nl');
        $this->actingAs($user)->get("/websites/{$ownSite->id}")->assertOk();
        $this->actingAs($user)->get("/websites/{$otherSite->id}")->assertNotFound();
        $this->actingAs($user)->put("/websites/{$otherSite->id}", ['php_version' => '8.5'])->assertNotFound();
    }

    public function test_client_can_switch_php_and_cache(): void
    {
        $customer = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'mine.nl', 'status' => 'active', 'php_version' => '8.3', 'page_cache' => true]);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->put("/websites/{$site->id}", ['php_version' => '8.4'])->assertRedirect();
        $this->actingAs($user)->put("/websites/{$site->id}", ['page_cache' => '0'])->assertRedirect();
        $this->assertSame('8.4', $site->fresh()->php_version);
        $this->assertFalse($site->fresh()->page_cache);
        $this->actingAs($user)->put("/websites/{$site->id}", ['php_version' => '7.4'])->assertSessionHasErrors('php_version');
    }
}
