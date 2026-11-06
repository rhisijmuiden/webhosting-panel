<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Rhis\CrmApi\Mail\CustomerWelcome;
use Rhis\CrmApi\Models\ApiToken;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\Webhooks;
use Tests\TestCase;

class CrmApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = ApiToken::issue('CRM');
        Http::fake(['https://crm.example.test/*' => Http::response('ok', 200)]);
        Mail::fake();
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        return $this->json($method, '/api/v1/crm'.$uri, $data, ['Authorization' => 'Bearer '.$this->token] + $headers);
    }

    private function seedPlanAndCustomer(): array
    {
        $plan = $this->api('POST', '/plans', ['code' => 'hosting-basic', 'name' => 'Hosting Basic', 'limits' => ['disk_mb' => 5000, 'domains' => 3, 'php_workers' => 4]])->assertCreated()->json();
        $customer = $this->api('POST', '/customers', ['external_id' => 'C-1001', 'name' => 'Jan Jansen', 'email' => 'jan@example.test', 'locale' => 'nl'])->assertCreated()->json();

        return [$plan, $customer];
    }

    private function webhook(array $events): string
    {
        return $this->api('POST', '/webhooks', ['url' => 'https://crm.example.test/hooks', 'events' => $events])->assertCreated()->json('secret');
    }

    public function test_requests_need_a_valid_token(): void
    {
        $this->getJson('/api/v1/crm/ping')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
        $this->getJson('/api/v1/crm/ping', ['Authorization' => 'Bearer whp_wrong'])->assertUnauthorized();
        $this->api('GET', '/ping')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('api_version', '1.0.0');

        ApiToken::query()->update(['revoked_at' => now()]);
        $this->api('GET', '/ping')->assertUnauthorized();
    }

    public function test_ip_allowlist(): void
    {
        [, $plain] = ApiToken::issue('Locked', ['203.0.113.0/24']);
        $this->getJson('/api/v1/crm/ping', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v1/crm/ping', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }

    public function test_tokens_are_stored_hashed(): void
    {
        $this->assertDatabaseMissing('crm_api_tokens', ['token_hash' => $this->token]);
        $this->assertDatabaseHas('crm_api_tokens', ['token_hash' => hash('sha256', $this->token)]);
    }

    public function test_plans_crud_with_code_ids(): void
    {
        $plan = $this->api('POST', '/plans', ['code' => 'pro', 'name' => 'Pro', 'limits' => ['disk_mb' => 20000, 'mailboxes' => null], 'features' => ['ssh_access' => true]])
            ->assertCreated()->assertJsonPath('limits.disk_mb', 20000)->assertJsonPath('limits.mailboxes', null)->assertJsonPath('features.ssh_access', true)->json();
        $this->assertSame('pro', HostingPackage::find($plan['id'])->crm_id);

        $this->api('GET', '/plans/code:pro')->assertOk()->assertJsonPath('id', $plan['id']);
        $this->api('PATCH', '/plans/code:pro', ['limits' => ['disk_mb' => 30000]])->assertOk()->assertJsonPath('limits.disk_mb', 30000);
        $this->api('POST', '/plans', ['code' => 'pro', 'name' => 'Again'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->api('POST', '/plans', ['code' => 'Bad Code'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['code', 'name']]]);
        $this->api('GET', '/plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', null);
        $this->api('DELETE', '/plans/code:pro')->assertNoContent();
        $this->api('GET', '/plans/code:pro')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_customer_is_created_with_a_login_and_welcome_mail(): void
    {
        [, $customer] = $this->seedPlanAndCustomer();

        $this->assertSame('C-1001', $customer['external_id']);
        $this->assertSame('jan@example.test', $customer['username']);
        $user = User::where('email', 'jan@example.test')->first();
        $this->assertFalse($user->isAdmin());
        $this->assertSame('nl', $user->locale);
        Mail::assertQueued(CustomerWelcome::class, fn ($m) => $m->hasTo('jan@example.test'));

        $this->api('GET', '/customers/ext:C-1001')->assertOk()->assertJsonPath('id', $customer['id']);
        $this->api('GET', '/customers?external_id=C-1001')->assertJsonCount(1, 'data');
        $this->api('PATCH', '/customers/ext:C-1001', ['company' => 'Jansen BV', 'address' => ['city' => 'IJmuiden', 'country' => 'NL']])
            ->assertOk()->assertJsonPath('company', 'Jansen BV')->assertJsonPath('address.city', 'IJmuiden');
        $this->api('POST', '/customers', ['external_id' => 'C-1001', 'name' => 'Dup', 'email' => 'other@example.test'])->assertStatus(409);
    }

    public function test_login_link_works_once(): void
    {
        $this->seedPlanAndCustomer();
        $url = $this->api('POST', '/customers/ext:C-1001/login-link', ['redirect' => '/websites'])->assertCreated()->json('url');

        $this->get($url)->assertRedirect('/websites');
        $this->assertAuthenticatedAs(User::where('email', 'jan@example.test')->first());
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();

        $this->api('POST', '/customers/ext:C-1001/login-link', ['redirect' => '//evil.example'])->assertStatus(422);
    }

    public function test_provisioning_a_subscription_end_to_end_with_signed_webhook(): void
    {
        [$plan, $customer] = $this->seedPlanAndCustomer();
        $secret = $this->webhook(['subscription.provisioned']);

        $res = $this->api('POST', '/subscriptions', [
            'customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'Jansen.NL',
            'php_version' => '8.3', 'mail' => ['provider' => 'm365'],
        ], ['Idempotency-Key' => 'ORD-1'])->assertStatus(202);

        $res->assertJsonPath('subscription.primary_domain', 'jansen.nl')->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('job.status', 'succeeded')->assertJsonPath('subscription.limits.disk_mb', 5000);
        $website = Website::where('domain', 'jansen.nl')->first();
        $this->assertSame('active', $website->status);
        $this->assertSame($customer['id'], (string) $website->customer_id);
        $this->assertSame((int) $plan['id'], Customer::find($customer['id'])->hosting_package_id);
        $this->api('GET', '/jobs/'.$res->json('job.id'))->assertOk()->assertJsonPath('status', 'succeeded');

        Http::assertSent(function (HttpRequest $r) use ($secret) {
            parse_str(str_replace(',', '&', $r->header('X-WHP-Signature')[0]), $sig);

            return $r->url() === 'https://crm.example.test/hooks'
                && $r->header('X-WHP-Event')[0] === 'subscription.provisioned'
                && hash_equals(hash_hmac('sha256', $sig['t'].'.'.$r->body(), $secret), $sig['v1'])
                && $r['data']['subscription']['external_id'] === 'ORD-1';
        });

        // Same Idempotency-Key: same answer, nothing created twice.
        $again = $this->api('POST', '/subscriptions', [
            'customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'Jansen.NL',
            'php_version' => '8.3', 'mail' => ['provider' => 'm365'],
        ], ['Idempotency-Key' => 'ORD-1'])->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($res->json('subscription.id'), $again->json('subscription.id'));
        $this->assertSame(1, Subscription::count());
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001'], ['Idempotency-Key' => 'ORD-1'])->assertStatus(409);

        // A domain that is already hosted.
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-2', 'primary_domain' => 'jansen.nl'])->assertStatus(409);
        $this->api('GET', '/subscriptions/ext:ORD-1/usage')->assertOk()->assertJsonPath('disk_mb.limit', 5000);
        $this->api('GET', '/usage?period=2026-10')->assertOk()->assertJsonPath('data.0.period', '2026-10');
        $this->api('GET', '/usage?period=2026-13')->assertStatus(422);
    }

    public function test_failed_provisioning_is_reported(): void
    {
        $this->seedPlanAndCustomer();
        $this->agent()->respond('site.add', new AgentException('command_failed', 'Disk full'));

        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-9', 'primary_domain' => 'fail.nl'])
            ->assertStatus(202)->assertJsonPath('subscription.status', 'failed')->assertJsonPath('job.status', 'failed')->assertJsonPath('job.error', 'Disk full');
    }

    public function test_suspend_and_unsuspend(): void
    {
        $this->seedPlanAndCustomer();
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'jansen.nl']);
        $this->agent()->respond('site.suspend', new AgentException('unknown_action', 'Unknown action'));
        $this->agent()->respond('site.unsuspend', new AgentException('command_failed', 'whp-site has no unsuspend yet'));

        $this->api('POST', '/subscriptions/ext:ORD-1/suspend', ['reason' => 'unpaid'])->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('suspension.reason', 'unpaid');
        $this->assertSame('suspended', Customer::where('crm_id', 'C-1001')->first()->status);
        $this->assertSame('suspended', Website::where('domain', 'jansen.nl')->first()->status);
        $this->api('POST', '/subscriptions/ext:ORD-1/suspend', ['reason' => 'unpaid'])->assertStatus(409);

        $this->api('POST', '/subscriptions/ext:ORD-1/unsuspend')->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('active', Customer::where('crm_id', 'C-1001')->first()->status);
        $this->api('POST', '/subscriptions/ext:ORD-1/suspend', ['reason' => 'because'])->assertStatus(422);
    }

    public function test_change_plan_applies_the_new_disk_quota(): void
    {
        $this->seedPlanAndCustomer();
        $this->api('POST', '/plans', ['code' => 'pro', 'name' => 'Pro', 'limits' => ['disk_mb' => 20000]]);
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'jansen.nl']);

        $this->api('PATCH', '/subscriptions/ext:ORD-1', ['plan_id' => 'code:pro', 'php_version' => '8.5'])->assertStatus(202)->assertJsonPath('job.status', 'succeeded');
        $quota = collect($this->agent()->calls)->firstWhere('action', 'site.quota');
        $this->assertSame(['domain' => 'jansen.nl', 'quota_mb' => 20000], $quota['params']);
        $this->assertSame('8.5', Website::where('domain', 'jansen.nl')->first()->php_version);
        $this->api('GET', '/subscriptions/ext:ORD-1')->assertJsonPath('limits.disk_mb', 20000);
    }

    public function test_terminate_makes_a_final_backup_first_and_can_be_undone(): void
    {
        $this->seedPlanAndCustomer();
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'jansen.nl']);

        $this->api('DELETE', '/subscriptions/ext:ORD-1?keep_backup_days=14')->assertStatus(202)->assertJsonPath('job.status', 'succeeded')->assertJsonPath('subscription.status', 'terminated');
        $actions = collect($this->agent()->calls)->pluck('action');
        $this->assertLessThan($actions->search('site.remove'), $actions->search('backup.run'));
        $this->assertSame(['site:jansen.nl', 'mail:jansen.nl'], collect($this->agent()->calls)->firstWhere('action', 'backup.run')['params']['scopes']);
        $this->assertNull(Website::where('domain', 'jansen.nl')->first());

        $this->api('POST', '/subscriptions/ext:ORD-1/restore')->assertStatus(202)->assertJsonPath('job.status', 'succeeded')->assertJsonPath('subscription.status', 'active');
        $this->assertSame('active', Website::where('domain', 'jansen.nl')->first()->status);
        $this->assertNotNull(collect($this->agent()->calls)->firstWhere('action', 'backup.restore'));
    }

    public function test_terminate_keeps_everything_when_the_backup_fails(): void
    {
        $this->seedPlanAndCustomer();
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'jansen.nl']);
        $this->agent()->respond('backup.run', new AgentException('not_found', 'no backup targets configured'));

        $this->api('DELETE', '/subscriptions/ext:ORD-1')->assertStatus(202)->assertJsonPath('job.status', 'failed')->assertJsonPath('subscription.status', 'active');
        $this->assertNotNull(Website::where('domain', 'jansen.nl')->first());
        $this->assertNull(collect($this->agent()->calls)->firstWhere('action', 'site.remove'));
    }

    public function test_catalog_and_orders_from_the_client_panel(): void
    {
        $this->seedPlanAndCustomer();
        $this->api('POST', '/subscriptions', ['customer_id' => 'ext:C-1001', 'plan_id' => 'code:hosting-basic', 'external_id' => 'ORD-1', 'primary_domain' => 'jansen.nl']);
        $this->webhook(['order.requested']);
        $this->api('PUT', '/catalog', ['shop_url' => 'https://www.rhis.nl/shop', 'products' => [[
            'code' => 'm365-business-basic', 'kind' => 'm365', 'name' => ['en' => 'Microsoft 365 Business Basic', 'nl' => 'Microsoft 365 Business Basic'],
            'price' => ['amount' => '6.50', 'interval' => 'month'], 'per_unit' => true,
        ]]])->assertOk()->assertJsonPath('products.0.price.currency', 'EUR');

        $user = User::where('email', 'jan@example.test')->first();
        $this->actingAs($user)->get('/shop')->assertOk()->assertSee('Microsoft 365 Business Basic')->assertSee('6,50');
        $this->actingAs($user)->post('/shop/orders', ['product' => 'm365-business-basic', 'quantity' => 5, 'domain' => 'jansen.nl'])->assertRedirect('/shop');
        $order = Order::first();
        $this->assertSame(5, $order->quantity);
        $this->assertSame('6.50', $order->price_shown['amount']);
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-WHP-Event')[0] === 'order.requested' && $r['data']['order']['quantity'] === 5);

        $this->actingAs($user)->post('/shop/orders', ['product' => 'm365-business-basic', 'domain' => 'not-mine.nl'])->assertSessionHasErrors('domain');
        $this->actingAs($user)->post('/shop/orders', ['product' => 'does-not-exist'])->assertSessionHasErrors('product');

        $this->api('PATCH', '/orders/'.$order->id, ['status' => 'awaiting_payment', 'checkout_url' => 'https://www.rhis.nl/pay/1', 'external_id' => 'Q-77'])
            ->assertOk()->assertJsonPath('status', 'awaiting_payment')->assertJsonPath('customer_external_id', 'C-1001');
        $this->actingAs($user)->get('/shop')->assertSee('https://www.rhis.nl/pay/1');

        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@example.test'])->id]);
        $this->actingAs($other)->delete('/shop/orders/'.$order->id)->assertNotFound();
        $this->actingAs($user)->delete('/shop/orders/'.$order->id)->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_webhook_test_and_failed_delivery(): void
    {
        Http::fake(['https://down.example.test/*' => Http::response('nope', 500)]);
        $hook = $this->api('POST', '/webhooks', ['url' => 'https://down.example.test/h', 'events' => ['ping']])->assertCreated()->json();
        $this->api('POST', '/webhooks', ['url' => 'http://insecure.example.test/h', 'events' => ['ping']])->assertStatus(422);

        $this->api('POST', '/webhooks/'.$hook['id'].'/test')->assertOk()->assertJsonPath('http_status', 500)->assertJsonPath('event', 'ping');
        $this->api('GET', '/webhooks')->assertOk()->assertJsonPath('data.0.last_delivery.event', 'ping');
        $this->assertSame('t=1,v1='.hash_hmac('sha256', '1.{}', 's'), Webhooks::signature('s', '{}', 1));
    }

    public function test_admin_api_page_and_tokens(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/settings/crm-api')->assertOk()->assertSee(url('/api/v1/crm'));

        $res = $this->post('/admin/settings/crm-api/tokens', ['name' => 'Website CRM', 'allowed_ips' => '203.0.113.10, 2001:db8::/48'])->assertRedirect();
        $plain = $res->getSession()->get('new_token');
        $this->assertStringStartsWith('whp_', $plain);
        $this->assertSame(['203.0.113.10', '2001:db8::/48'], ApiToken::where('name', 'Website CRM')->first()->allowed_ips);
        $this->post('/admin/settings/crm-api/tokens', ['name' => 'Bad', 'allowed_ips' => 'not-an-ip'])->assertSessionHasErrors('allowed_ips');

        $token = ApiToken::where('name', 'Website CRM')->first();
        $this->delete('/admin/settings/crm-api/tokens/'.$token->id)->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
    }
}
