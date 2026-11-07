<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDomainListCompactTest extends TestCase
{
    use RefreshDatabase;

    public function test_domains_can_be_sorted_by_owner(): void
    {
        $this->actingAsAdmin();
        $zed = Customer::create(['name' => 'Zed', 'email' => 'zed@example.test']);
        // Company name wins over the person's name, as in the Owner column
        $acme = Customer::create(['name' => 'Yvonne', 'company' => 'Acme', 'email' => 'acme@example.test']);
        $bob = Customer::create(['name' => 'Bob', 'email' => 'bob@example.test']);
        Website::create(['customer_id' => $zed->id, 'domain' => 'first.test', 'status' => 'active']);
        Website::create(['customer_id' => $acme->id, 'domain' => 'second.test', 'status' => 'active']);
        Website::create(['customer_id' => $bob->id, 'domain' => 'third.test', 'status' => 'active']);

        $this->get('/admin/websites?sort=owner&dir=asc')->assertOk()
            ->assertSeeInOrder(['second.test', 'third.test', 'first.test']);
        $this->get('/admin/websites?sort=owner&dir=desc')->assertOk()
            ->assertSeeInOrder(['first.test', 'third.test', 'second.test']);
    }

    public function test_owner_header_is_a_sort_link_and_actions_are_one_row(): void
    {
        $this->actingAsAdmin();
        $c = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        Website::create(['customer_id' => $c->id, 'domain' => 'alpha.test', 'status' => 'active']);

        $html = $this->get('/admin/websites')->assertOk()->getContent();
        $this->assertStringContainsString('sort=owner', $html);
        $this->assertStringContainsString('class="row-actions"', $html);
        $this->assertStringNotContainsString('class="quick-actions"', $html);
    }
}
