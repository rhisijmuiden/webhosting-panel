<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Testing\FakeCloudflare;
use Tests\TestCase;

class DnsMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cf = new FakeCloudflare;
        $this->app->instance(HttpClient::class, $this->cf);
        $settings = $this->app->make(Settings::class);
        $settings->set('dnsmail.cloudflare_token', 'tok', encrypted: true);
        $settings->set('dnsmail.mail_host', 'mail.rhis.nl');
        $settings->set('dnsmail.ipv4', '192.0.2.10');
        $settings->set('dnsmail.webmail_host', 'webmail.rhis.nl');
    }

    private function customer(int $maxMailboxes = 10): Customer
    {
        $package = HostingPackage::create(['name' => 'Start', 'max_mailboxes' => $maxMailboxes]);

        return Customer::create(['name' => 'Jan', 'email' => 'jan@example.test', 'hosting_package_id' => $package->id]);
    }

    private function records(string $zone, ?string $type = null, ?string $name = null): array
    {
        return array_values(array_filter($this->cf->records($zone), fn ($r) => ($type === null || $r['type'] === $type) && ($name === null || $r['name'] === $name)));
    }

    public function test_new_website_gets_mail_domain_dkim_and_dns(): void
    {
        $this->cf->addZone('klant.nl');
        $site = Website::create(['customer_id' => $this->customer()->id, 'domain' => 'klant.nl', 'status' => 'active', 'aliases' => ['www.klant.nl']]);

        $domain = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        $this->assertSame($site->id, $domain->website_id);
        $this->assertNotNull($domain->dkim_public_key);
        $this->assertSame('in_sync', $domain->dns_status);
        $this->assertSame('mail.rhis.nl', $this->records('klant.nl', 'MX')[0]['content']);
        $this->assertSame('192.0.2.10', $this->records('klant.nl', 'A', 'www.klant.nl')[0]['content']);
        $this->assertTrue($this->records('klant.nl', 'A', 'klant.nl')[0]['proxied']);
        $this->assertStringStartsWith('v=DKIM1', $this->records('klant.nl', 'TXT', 'whp._domainkey.klant.nl')[0]['content']);
        $this->assertCount(1, $this->records('klant.nl', 'SRV', '_imaps._tcp.klant.nl'));
        $this->assertStringStartsWith(DnsRecord::MANAGED_MARKER, $this->records('klant.nl', 'MX')[0]['comment']);
        $this->assertContains('mail.set_routing', array_column($this->agent()->calls, 'action'));
    }

    public function test_switching_to_microsoft_365_replaces_mail_records(): void
    {
        $this->cf->addZone('klant.nl');
        Website::create(['customer_id' => $this->customer()->id, 'domain' => 'klant.nl', 'status' => 'active']);
        $domain = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        $this->actingAsAdmin();

        $this->put("/admin/domains/{$domain->id}/mail", [
            'provider' => 'microsoft365', 'spf_all' => '-', 'dmarc_policy' => 'none', 'mta_sts_mode' => 'testing',
            'm365_tenant' => 'klant', 'm365_verification' => 'MS=ms12345678', 'server_sends' => '1', 'dns_managed' => '1', 'proxied' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('klant-nl.mail.protection.outlook.com', $this->records('klant.nl', 'MX')[0]['content']);
        $this->assertSame([], $this->records('klant.nl', 'SRV', '_imaps._tcp.klant.nl'));
        $this->assertSame('autodiscover.outlook.com', $this->records('klant.nl', 'CNAME', 'autodiscover.klant.nl')[0]['content']);
        $this->assertSame('selector1-klant-nl._domainkey.klant.onmicrosoft.com', $this->records('klant.nl', 'CNAME', 'selector1._domainkey.klant.nl')[0]['content']);
        $txt = array_column($this->records('klant.nl', 'TXT', 'klant.nl'), 'content');
        $this->assertContains('MS=ms12345678', $txt);
        $this->assertContains('v=spf1 include:spf.protection.outlook.com ip4:192.0.2.10 -all', $txt);

        $routing = collect($this->agent()->calls)->where('action', 'mail.set_routing')->last()['params'];
        $this->assertSame('microsoft365', $routing['domains'][0]['provider']);
    }

    public function test_foreign_records_are_a_conflict_until_taken_over(): void
    {
        $this->cf->addZone('klant.nl', [['type' => 'MX', 'name' => 'klant.nl', 'content' => 'mx.oldhost.nl', 'priority' => 10]]);
        $customer = $this->customer();
        $domain = MailDomain::create(['customer_id' => $customer->id, 'domain' => 'klant.nl', 'dkim_public_key' => 'QUJD']);
        $this->actingAsAdmin();

        $this->get("/admin/domains/{$domain->id}?tab=dns")->assertOk()->assertSee('mx.oldhost.nl')->assertSee(__('These records already exist with other values'));
        $this->assertSame('conflict', $domain->fresh()->dns_status);

        $this->post("/admin/domains/{$domain->id}/dns/apply", ['adopt' => '0'])->assertRedirect();
        $this->assertSame('mx.oldhost.nl', $this->records('klant.nl', 'MX')[0]['content']);

        $this->get("/admin/domains/{$domain->id}?tab=dns&adopt=1")->assertOk()->assertSee(__('Take over and publish'));
        $this->post("/admin/domains/{$domain->id}/dns/apply", ['adopt' => '1'])->assertRedirect();
        $this->assertSame('mail.rhis.nl', $this->records('klant.nl', 'MX')[0]['content']);
        $this->assertSame('in_sync', $domain->fresh()->dns_status);
    }

    public function test_own_records_can_be_added_and_removed_but_managed_ones_not(): void
    {
        $this->cf->addZone('klant.nl');
        $site = Website::create(['customer_id' => $this->customer()->id, 'domain' => 'klant.nl', 'status' => 'active']);
        $domain = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        $user = User::factory()->create(['customer_id' => $site->customer_id]);

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/records", ['type' => 'TXT', 'name' => '@', 'content' => 'google-site-verification=abc', 'ttl' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $own = collect($this->records('klant.nl', 'TXT'))->firstWhere('content', 'google-site-verification=abc');
        $this->assertNotNull($own);
        $this->assertArrayNotHasKey('comment', $own);

        // A later sync leaves it alone.
        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/apply")->assertRedirect();
        $this->assertNotNull(collect($this->records('klant.nl', 'TXT'))->firstWhere('content', 'google-site-verification=abc'));

        $mx = $this->records('klant.nl', 'MX')[0];
        $this->actingAs($user)->delete("/mail/domains/{$domain->id}/dns/records/{$mx['id']}")->assertSessionHasErrors('dns');
        $this->actingAs($user)->delete("/mail/domains/{$domain->id}/dns/records/{$own['id']}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(collect($this->records('klant.nl', 'TXT'))->firstWhere('content', 'google-site-verification=abc'));

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/records", ['type' => 'A', 'name' => 'shop', 'content' => 'not-an-ip'])->assertSessionHasErrors('content');
    }

    public function test_mailboxes_respect_the_package_and_reach_the_agent(): void
    {
        $customer = $this->customer(maxMailboxes: 1);
        $domain = MailDomain::create(['customer_id' => $customer->id, 'domain' => 'klant.nl', 'dns_managed' => false]);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/mailboxes", [
            'local_part' => 'Jan', 'password' => 'een-lang-wachtwoord', 'password_confirmation' => 'een-lang-wachtwoord', 'quota_mb' => 1024,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $box = Mailbox::firstOrFail();
        $this->assertSame('jan', $box->local_part);
        $this->assertStringStartsWith('{ARGON2ID}$argon2id$', $box->password_hash);
        $this->assertTrue(password_verify('een-lang-wachtwoord', substr($box->password_hash, 10)));

        $sync = collect($this->agent()->calls)->where('action', 'mail.mailboxes_sync')->last()['params'];
        $this->assertSame('jan@klant.nl', $sync['mailboxes'][0]['email']);
        $this->assertSame(1024, $sync['mailboxes'][0]['quota_mb']);

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/mailboxes", [
            'local_part' => 'piet', 'password' => 'een-lang-wachtwoord', 'password_confirmation' => 'een-lang-wachtwoord', 'quota_mb' => 1024,
        ])->assertSessionHasErrors('local_part');
        $this->assertSame(1, Mailbox::count());

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/aliases", ['local_part' => 'info', 'targets' => 'jan@klant.nl, jan@gmail.com'])->assertRedirect();
        $this->actingAs($user)->put("/mail/domains/{$domain->id}/mailboxes/{$box->id}", ['quota_mb' => 2048, 'enabled' => '1', 'send_as' => 'info@klant.nl'])->assertSessionHasNoErrors();
        $sync = collect($this->agent()->calls)->where('action', 'mail.mailboxes_sync')->last()['params'];
        $this->assertSame(['info@klant.nl'], $sync['mailboxes'][0]['send_as']);
        $this->assertSame(['jan@gmail.com', 'jan@klant.nl'], collect($sync['aliases'][0]['targets'])->sort()->values()->all());
        $this->actingAs($user)->put("/mail/domains/{$domain->id}/mailboxes/{$box->id}", ['quota_mb' => 2048, 'send_as' => 'ceo@other.nl'])->assertSessionHasErrors('send_as');

        $this->actingAs($user)->get("/mail/domains/{$domain->id}/mailboxes/{$box->id}/profile")
            ->assertOk()->assertHeader('Content-Type', 'application/x-apple-aspen-config')->assertSee('jan@klant.nl', false);
    }

    public function test_customers_only_see_their_own_domains(): void
    {
        $mine = $this->customer();
        $other = Customer::create(['name' => 'Ander', 'email' => 'a@example.test']);
        $own = MailDomain::create(['customer_id' => $mine->id, 'domain' => 'mijn.nl', 'dns_managed' => false]);
        $foreign = MailDomain::create(['customer_id' => $other->id, 'domain' => 'ander.nl', 'dns_managed' => false]);
        $user = User::factory()->create(['customer_id' => $mine->id]);

        $this->actingAs($user)->get('/mail/domains')->assertOk()->assertSee('mijn.nl')->assertDontSee('ander.nl');
        foreach (['mail', 'mailboxes', 'dns'] as $tab) {
            $this->actingAs($user)->get("/mail/domains/{$own->id}?tab={$tab}")->assertOk();
        }
        $this->actingAs($user)->get("/mail/domains/{$foreign->id}")->assertNotFound();
        $this->actingAs($user)->post("/mail/domains/{$foreign->id}/mailboxes", ['local_part' => 'x'])->assertNotFound();
        $this->actingAs($user)->get('/admin/domains')->assertForbidden();
    }

    public function test_admin_pages_render_and_settings_save(): void
    {
        $this->cf->addZone('klant.nl');
        $customer = $this->customer();
        $domain = MailDomain::create(['customer_id' => $customer->id, 'domain' => 'klant.nl', 'provider' => 'microsoft365']);
        $this->actingAsAdmin();
        $this->app->make(Settings::class)->set('dnsmail.m365_order_url', 'https://rhis.nl/shop/m365');

        foreach (['/admin/domains', '/admin/domains/create', '/admin/settings/dns-mail', "/admin/domains/{$domain->id}",
            "/admin/domains/{$domain->id}?tab=mailboxes", "/admin/domains/{$domain->id}?tab=dns"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/admin/domains/{$domain->id}")->assertSee(__('Microsoft 365 setup'))->assertSee('https://rhis.nl/shop/m365');

        $this->put('/admin/settings/dns-mail', ['mail_host' => 'mx1.rhis.nl', 'ipv4' => '192.0.2.20', 'cloudflare_token' => 'new_token-1'])->assertSessionHasNoErrors();
        $this->assertSame('new_token-1', $this->app->make(Settings::class)->get('dnsmail.cloudflare_token'));
        $this->post('/admin/settings/dns-mail/test')->assertSessionHas('status');

        $this->post('/admin/domains', ['customer_id' => $customer->id, 'domain' => 'Nieuw.nl', 'provider' => 'rhis'])->assertRedirect();
        $this->assertDatabaseHas('mail_domains', ['domain' => 'nieuw.nl']);

        $this->delete("/admin/domains/{$domain->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->delete("/admin/domains/{$domain->id}", ['confirm' => 'klant.nl'])->assertRedirect('/admin/domains');
        $this->assertDatabaseMissing('mail_domains', ['domain' => 'klant.nl']);
    }
}
