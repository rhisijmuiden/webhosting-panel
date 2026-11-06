<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Rhis\CrmApi\Models\ApiToken;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Models\WebhookDelivery;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\MailPusher;
use Rhis\DomainAliases\Models\DomainAlias;
use Tests\TestCase;

class DomainAliasesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $package = []): array
    {
        $plan = HostingPackage::create(array_merge(['name' => 'Basic', 'disk_mb' => 5000, 'max_websites' => 1], $package));
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.nl', 'hosting_package_id' => $plan->id]);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => 'klant.nl', 'status' => 'active', 'php_version' => '8.4', 'aliases' => ['www.klant.nl']]);

        return [$customer, $user, $site];
    }

    private function addAlias(User $user, Website $site, array $form = [])
    {
        return $this->actingAs($user)->post("/domain-aliases/websites/{$site->id}", array_merge(
            ['domain' => 'klant.be', 'mode' => 'serve', 'www' => '1', 'mail' => '1'], $form));
    }

    public function test_client_adds_an_alias_with_web_ssl_and_mail_forwarding(): void
    {
        [, $user, $site] = $this->customer();
        $main = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        $main->mailboxes()->create(['local_part' => 'info', 'password_hash' => Mailbox::hashPassword('een-lang-wachtwoord')]);
        $main->aliases()->create(['local_part' => 'sales', 'targets' => ['info@klant.nl']]);

        $this->actingAs($user)->get("/websites/{$site->id}")->assertOk()->assertSee(__('Domain aliases'))->assertSee(__('Add alias'));
        $this->addAlias($user, $site, ['domain' => 'https://Klant.BE/'])->assertRedirect()->assertSessionHasNoErrors();

        $alias = DomainAlias::sole();
        $this->assertSame(['klant.be', 'active', 'active', true], [$alias->domain, $alias->status, $alias->ssl_status, $alias->www]);
        $call = collect($this->agent()->calls)->firstWhere('action', 'site.alias_add');
        $this->assertSame(['domain' => 'klant.nl', 'alias' => 'klant.be', 'mode' => 'serve', 'www' => true, 'ssl' => true], $call['params']);

        $md = MailDomain::where('domain', 'klant.be')->firstOrFail();
        $this->assertSame([$main->id, 'rhis', $site->id], [$md->alias_of_id, $md->provider, $md->website_id]);
        $params = app(MailPusher::class)->mailboxParams();
        $this->assertContains(['address' => 'info@klant.be', 'targets' => ['info@klant.nl']], $params['aliases']);
        $this->assertContains(['address' => 'sales@klant.be', 'targets' => ['info@klant.nl']], $params['aliases']);
        $this->assertContains('info@klant.be', $params['mailboxes'][0]['send_as']);

        $this->actingAs($user)->get('/domain-aliases')->assertOk()->assertSee('klant.be')->assertSee('www.klant.be');
    }

    public function test_names_in_use_and_the_package_limit_are_refused(): void
    {
        [$customer, $user, $site] = $this->customer();
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@x.nl']);
        $other->websites()->create(['domain' => 'piet.nl', 'status' => 'active', 'aliases' => ['www.piet.nl']]);

        foreach (['klant.nl', 'www.klant.nl', 'piet.nl', 'www.piet.nl', 'not a domain'] as $taken) {
            $this->addAlias($user, $site, ['domain' => $taken])->assertSessionHasErrors('domain');
        }
        $this->assertSame(0, DomainAlias::count());

        $customer->package->forceFill(['crm_limits' => json_encode(['domain_aliases' => 1])])->save();
        $this->addAlias($user, $site)->assertSessionHasNoErrors();
        $this->addAlias($user, $site, ['domain' => 'klant.de'])->assertSessionHasErrors('domain');
        $this->addAlias($user, $site, ['domain' => 'klant.be'])->assertSessionHasErrors('domain');
        $this->actingAs($user)->get("/websites/{$site->id}")->assertSee('1 of 1 used');

        // Admins are not bound to the package limit.
        $this->actingAsAdmin();
        $this->post("/admin/domain-aliases/websites/{$site->id}", ['domain' => 'klant.de', 'mode' => 'redirect'])->assertSessionHasNoErrors();
        $this->assertSame('redirect', DomainAlias::where('domain', 'klant.de')->value('mode'));
    }

    public function test_mode_mail_ssl_and_remove(): void
    {
        [, $user, $site] = $this->customer();
        $this->addAlias($user, $site);
        $alias = DomainAlias::sole();

        $this->actingAs($user)->put("/domain-aliases/{$alias->id}", ['mode' => 'redirect'])->assertSessionHasNoErrors();
        $this->assertSame(['domain' => 'klant.nl', 'alias' => 'klant.be', 'mode' => 'redirect'], collect($this->agent()->calls)->firstWhere('action', 'site.alias_mode')['params']);
        $this->assertSame('redirect', $alias->fresh()->mode);

        $this->actingAs($user)->put("/domain-aliases/{$alias->id}", ['mail' => '0'])->assertSessionHasNoErrors();
        $this->assertNull(MailDomain::where('domain', 'klant.be')->value('alias_of_id'));
        $this->assertSame('external', MailDomain::where('domain', 'klant.be')->value('provider'));

        $this->agent()->respond('site.alias_ssl', fn ($p) => ['alias' => ['domain' => $p['alias'], 'ssl' => 'pending']]);
        $this->actingAs($user)->post("/domain-aliases/{$alias->id}/ssl")->assertSessionHas('status');

        $this->agent()->respond('site.alias_remove', new AgentException('command_failed', 'nginx failed'));
        $this->actingAs($user)->delete("/domain-aliases/{$alias->id}")->assertSessionHasErrors('alias');
        $this->assertNotNull($alias->fresh());

        $this->agent()->respond('site.alias_remove', ['domain_aliases' => []]);
        $this->actingAs($user)->delete("/domain-aliases/{$alias->id}")->assertSessionHasNoErrors();
        $this->assertNull($alias->fresh());
        $this->assertNull(MailDomain::where('domain', 'klant.be')->first());
    }

    public function test_failed_alias_is_shown_and_can_be_removed(): void
    {
        [, $user, $site] = $this->customer();
        $this->agent()->respond('site.alias_add', new AgentException('command_failed', 'klant.be is already in use by site x.nl'));
        $this->addAlias($user, $site);
        $alias = DomainAlias::sole();
        $this->assertSame('failed', $alias->status);
        $this->actingAs($user)->get("/websites/{$site->id}")->assertSee('already in use by site x.nl');

        $this->agent()->respond('site.alias_remove', new AgentException('command_failed', 'klant.be is not an alias of klant.nl'));
        $this->actingAs($user)->delete("/domain-aliases/{$alias->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, DomainAlias::count());
    }

    public function test_customers_only_see_their_own_aliases(): void
    {
        [, $user, $site] = $this->customer();
        $this->addAlias($user, $site);
        $alias = DomainAlias::sole();
        $stranger = User::factory()->create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@x.nl'])->id]);

        $this->flushSession();
        $this->actingAs($stranger)->get('/domain-aliases')->assertOk()->assertDontSee('klant.be');
        $this->actingAs($stranger)->put("/domain-aliases/{$alias->id}", ['mode' => 'redirect'])->assertNotFound();
        $this->actingAs($stranger)->delete("/domain-aliases/{$alias->id}")->assertNotFound();
        $this->actingAs($stranger)->post("/domain-aliases/websites/{$site->id}", ['domain' => 'x.be', 'mode' => 'serve'])->assertNotFound();
        $this->actingAs($stranger)->get("/domain-aliases/{$alias->id}/convert")->assertNotFound();
    }

    public function test_convert_directly_when_the_package_has_room(): void
    {
        [, $user, $site] = $this->customer(['max_websites' => 3]);
        MailDomain::where('domain', 'klant.nl')->firstOrFail()->mailboxes()->create(['local_part' => 'info', 'password_hash' => Mailbox::hashPassword('een-lang-wachtwoord')]);
        $this->addAlias($user, $site, ['www' => '0']);
        $alias = DomainAlias::sole();

        $this->actingAs($user)->get("/domain-aliases/{$alias->id}/convert")->assertOk()->assertSee(__('Convert now'));
        $this->actingAs($user)->post("/domain-aliases/{$alias->id}/convert", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $res = $this->actingAs($user)->post("/domain-aliases/{$alias->id}/convert", ['confirm' => 'klant.be']);

        $new = Website::where('domain', 'klant.be')->firstOrFail();
        $res->assertRedirect(route('client.websites.show', $new));
        $this->assertSame(0, DomainAlias::count());
        $this->assertSame(['domain' => 'klant.nl', 'alias' => 'klant.be', 'keep_cert' => true], collect($this->agent()->calls)->firstWhere('action', 'site.alias_remove')['params']);
        $add = collect($this->agent()->calls)->where('action', 'site.add')->last()['params'];
        $this->assertSame(['klant.be', '8.4', true], [$add['domain'], $add['php'], $add['no_alias']]);

        // Mail keeps flowing through ordinary aliases on the new website's mail domain.
        $md = MailDomain::where('domain', 'klant.be')->firstOrFail();
        $this->assertSame([null, $new->id], [$md->alias_of_id, $md->website_id]);
        $this->assertSame(['info@klant.nl'], MailAlias::where('mail_domain_id', $md->id)->where('local_part', 'info')->value('targets'));
    }

    public function test_upgrade_order_through_the_crm_converts_the_alias(): void
    {
        Http::fake(['https://crm.example.test/*' => Http::response('ok', 200)]);
        Mail::fake();
        [, $token] = ApiToken::issue('CRM');
        $api = fn (string $m, string $uri, array $data = []) => $this->json($m, '/api/v1/crm'.$uri, $data, ['Authorization' => 'Bearer '.$token]);
        $api('POST', '/webhooks', ['url' => 'https://crm.example.test/hooks', 'events' => ['order.requested', 'domain_alias.converted']])->assertCreated();
        $api('PUT', '/catalog', ['products' => [[
            'code' => 'extra-website', 'kind' => 'alias_upgrade', 'name' => ['en' => 'Extra website', 'nl' => 'Extra website'],
            'price' => ['amount' => '2.50', 'interval' => 'month'],
        ]]])->assertOk();

        [, $user, $site] = $this->customer(['max_websites' => 1]);
        $this->addAlias($user, $site);
        $alias = DomainAlias::sole();

        $this->actingAs($user)->get("/domain-aliases/{$alias->id}/convert")->assertOk()->assertDontSee(__('Convert now'))->assertSee('Extra website')->assertSee('2,50');
        $this->actingAs($user)->post("/domain-aliases/{$alias->id}/convert", ['confirm' => 'klant.be'])->assertForbidden();
        $this->actingAs($user)->post("/domain-aliases/{$alias->id}/upgrade", ['product' => 'nope'])->assertSessionHasErrors('alias');
        $this->actingAs($user)->post("/domain-aliases/{$alias->id}/upgrade", ['product' => 'extra-website'])->assertSessionHas('status');

        $order = Order::sole();
        $this->assertSame([$order->id, 'requested'], [$alias->fresh()->upgrade_order_id, $order->status]);
        $this->assertStringContainsString('domain_alias:klant.be', $order->note);
        $this->assertSame(1, WebhookDelivery::where('event', 'order.requested')->count());
        $this->actingAs($user)->get("/domain-aliases/{$alias->id}/convert")->assertSee(__('Upgrade ordered'));

        $api('PATCH', "/orders/{$order->id}", ['status' => 'accepted'])->assertOk();
        $this->assertSame(0, DomainAlias::count());
        $this->assertTrue(Website::where('domain', 'klant.be')->exists());
        $delivery = WebhookDelivery::where('event', 'domain_alias.converted')->sole();
        $this->assertSame($order->id, $delivery->payload['data']['order_id']);
        $this->assertSame('klant.be', $delivery->payload['data']['domain_alias']['domain']);
    }

    public function test_crm_api_manages_and_converts_aliases(): void
    {
        Http::fake(['https://crm.example.test/*' => Http::response('ok', 200)]);
        Mail::fake();
        [, $token] = ApiToken::issue('CRM');
        $api = fn (string $m, string $uri, array $data = []) => $this->json($m, '/api/v1/crm'.$uri, $data, ['Authorization' => 'Bearer '.$token]);
        $api('POST', '/plans', ['code' => 'basic', 'name' => 'Basic', 'limits' => ['domains' => 1, 'domain_aliases' => 2]])->assertCreated();
        $api('POST', '/plans', ['code' => 'pro', 'name' => 'Pro', 'limits' => ['domains' => 5]])->assertCreated();
        $api('POST', '/customers', ['external_id' => 'C-1', 'name' => 'Jan', 'email' => 'jan@klant.nl'])->assertCreated();
        $api('POST', '/subscriptions', ['customer_id' => 'ext:C-1', 'plan_id' => 'code:basic', 'external_id' => 'S-1', 'primary_domain' => 'klant.nl'])->assertStatus(202);

        $created = $api('POST', '/subscriptions/ext:S-1/domain-aliases', ['domain' => 'klant.be', 'mode' => 'redirect', 'mail' => false])
            ->assertStatus(202)->assertJsonPath('domain', 'klant.be')->assertJsonPath('subscription_external_id', 'S-1')->json();
        $api('GET', '/domain-aliases?subscription_id=ext:S-1')->assertOk()->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.mode', 'redirect');
        $api('POST', '/subscriptions/ext:S-1/domain-aliases', ['domain' => 'klant.nl'])->assertStatus(409);
        $api('POST', '/subscriptions/ext:S-1/domain-aliases', ['domain' => 'klant.de', 'enforce_limit' => true])->assertStatus(202);
        $api('POST', '/subscriptions/ext:S-1/domain-aliases', ['domain' => 'klant.fr', 'enforce_limit' => true])->assertStatus(409);
        $api('PATCH', '/domain-aliases/domain:klant.de', ['mode' => 'redirect'])->assertOk()->assertJsonPath('mode', 'redirect');
        $api('DELETE', '/domain-aliases/domain:klant.de')->assertNoContent();
        $api('GET', '/domain-aliases/domain:klant.de')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $api('POST', "/domain-aliases/{$created['id']}/convert", ['plan_id' => 'code:pro'])->assertStatus(422);
        $res = $api('POST', "/domain-aliases/{$created['id']}/convert", ['plan_id' => 'code:pro', 'external_id' => 'S-2'])->assertStatus(202);
        $sub = Subscription::where('external_id', 'S-2')->firstOrFail();
        $res->assertJsonPath('subscription_id', $sub->id)->assertJsonPath('website.domain', 'klant.be');
        $this->assertSame('klant.be', $sub->website->domain);
        $this->assertSame(0, DomainAlias::count());
    }

    public function test_admin_overview_and_direct_convert(): void
    {
        [, $user, $site] = $this->customer(['max_websites' => 1]);
        $this->addAlias($user, $site);
        $alias = DomainAlias::sole();
        $this->actingAsAdmin();

        $this->get('/admin/domain-aliases')->assertOk()->assertSee('klant.be')->assertSee('Jan');
        $this->get('/admin')->assertSee(route('admin.domain-aliases.index'), false);
        $this->get("/admin/websites/{$site->id}")->assertOk()->assertSee('klant.be');
        $this->get("/admin/domain-aliases/{$alias->id}/convert")->assertOk()->assertSee(__('Convert now'));
        $this->post("/admin/domain-aliases/{$alias->id}/convert", ['confirm' => 'klant.be'])->assertRedirect();
        $this->assertTrue(Website::where('domain', 'klant.be')->exists());
    }

    public function test_dutch_translation(): void
    {
        [, $user, $site] = $this->customer();
        $user->update(['locale' => 'nl']);
        $this->actingAs($user)->withSession(['locale' => 'nl'])->get('/domain-aliases')->assertOk()->assertSee('Domeinaliassen');
    }
}
