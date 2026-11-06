<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\Resolver;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Models\DnsOnboarding;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Notifications\DnsLiveNotification;
use Rhis\DnsMail\Testing\FakeCloudflare;
use Rhis\DnsMail\Testing\FakeResolver;
use Tests\TestCase;

class DnsOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private FakeCloudflare $cf;

    private FakeResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cf = new FakeCloudflare;
        $this->dns = new FakeResolver;
        $this->app->instance(HttpClient::class, $this->cf);
        $this->app->instance(Resolver::class, $this->dns);
        $settings = $this->app->make(Settings::class);
        $settings->set('dnsmail.cloudflare_token', 'tok', encrypted: true);
        $settings->set('dnsmail.mail_host', 'mail.rhis.nl');
        $settings->set('dnsmail.ipv4', '192.0.2.10');
    }

    /** A customer whose domain is not in our Cloudflare account yet. */
    private function setUpCustomer(): array
    {
        $package = HostingPackage::create(['name' => 'Start', 'max_mailboxes' => 10]);
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $this->withPackage(Website::create(['customer_id' => $customer->id, 'domain' => 'klant.nl', 'status' => 'active']), $package);
        $domain = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        $user = User::factory()->create(['customer_id' => $customer->id, 'role' => User::ROLE_CLIENT, 'is_active' => true]);
        $this->dns->delegate('klant.nl', 'ns1.oldhost.nl', 'ns2.oldhost.nl');

        return [$customer, $domain, $user];
    }

    private function records(string $zone, string $type, string $name): array
    {
        return array_values(array_filter($this->cf->records($zone), fn ($r) => $r['type'] === $type && $r['name'] === $name));
    }

    public function test_wizard_adds_the_zone_and_waits_for_the_nameservers(): void
    {
        [, $domain, $user] = $this->setUpCustomer();
        $this->assertSame('no_zone', $domain->fresh()->dns_status);
        $this->cf->scannable['klant.nl'] = [['type' => 'TXT', 'name' => 'klant.nl', 'content' => 'google-site-verification=abc', 'ttl' => 1]];

        $this->actingAs($user)->get("/mail/domains/{$domain->id}?tab=dns")->assertOk()->assertSee('Start the DNS wizard');
        $this->actingAs($user)->get("/mail/domains/{$domain->id}/dns/connect")->assertOk()->assertSee('Add klant.nl');
        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/connect")->assertRedirect()->assertSessionHasNoErrors();

        $zone = $this->cf->zoneByName('klant.nl');
        $this->assertSame('pending', $zone['status']);
        $o = DnsOnboarding::firstOrFail();
        $this->assertTrue($o->isPending());
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $o->name_servers);
        $this->assertSame(1, $o->imported_records);
        $this->assertSame($zone['id'], $domain->fresh()->cloudflare_zone_id);

        // Records are ready before the switch; the scanned TXT record is kept.
        $this->assertSame('mail.rhis.nl', $this->records('klant.nl', 'MX', 'klant.nl')[0]['content']);
        $this->assertCount(1, array_filter($this->records('klant.nl', 'TXT', 'klant.nl'), fn ($r) => $r['content'] === 'google-site-verification=abc'));
        $mailA = $this->records('klant.nl', 'A', 'mail.klant.nl');
        $this->assertSame('192.0.2.10', $mailA[0]['content']);
        $this->assertFalse($mailA[0]['proxied']);

        $this->actingAs($user)->get("/mail/domains/{$domain->id}/dns/connect")->assertOk()
            ->assertSee('ada.ns.cloudflare.com')->assertSee('ns1.oldhost.nl')->assertSee('Old Registrar B.V.')->assertSee('Check now');
        $this->actingAs($user)->get('/dashboard')->assertSee('Waiting for the nameservers of klant.nl');

        // Not changed yet: still pending.
        $this->travel(1)->minutes();
        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/connect/check")->assertSessionHas('status');
        $this->assertTrue($o->fresh()->isPending());
        $this->assertSame(1, $this->cf->activationChecks);
    }

    public function test_scheduler_notices_the_switch_and_mails_the_customer_once(): void
    {
        Notification::fake();
        [, $domain, $user] = $this->setUpCustomer();
        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/connect")->assertSessionHasNoErrors();
        $o = DnsOnboarding::firstOrFail();

        $this->travel(6)->minutes();
        $this->artisan('dns-mail:check')->assertSuccessful();
        $this->assertTrue($o->fresh()->isPending());
        Notification::assertNothingSent();

        // The customer changed the nameservers; Cloudflare activates the zone.
        $this->dns->delegate('klant.nl', 'bob.ns.cloudflare.com', 'ada.ns.cloudflare.com');
        $this->cf->activate('klant.nl');
        $this->travel(6)->minutes();
        $this->artisan('dns-mail:check')->assertSuccessful();

        $o->refresh();
        $this->assertTrue($o->isActive());
        $this->assertNotNull($o->activated_at);
        $this->assertTrue($o->nameserversMatch());
        Notification::assertSentTo($user, DnsLiveNotification::class, function (DnsLiveNotification $n) use ($user) {
            $mail = $n->toMail($user);

            return str_contains($mail->subject, 'klant.nl') && $n->nameServers === ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'];
        });

        $this->travel(10)->minutes();
        $this->artisan('dns-mail:check')->assertSuccessful();
        Notification::assertSentToTimes($user, DnsLiveNotification::class, 1);

        // Portal notice until the customer has seen it.
        $this->actingAs($user)->get('/dashboard')->assertSee('DNS for klant.nl is now live at RHIS');
        $this->actingAs($user)->get("/mail/domains/{$domain->id}/dns/connect")->assertOk()->assertSee('Your DNS is now live at RHIS Cloudflare');
        $this->actingAs($user)->get('/dashboard')->assertDontSee('DNS for klant.nl is now live at RHIS');
    }

    public function test_checks_slow_down_over_time(): void
    {
        $o = new DnsOnboarding(['status' => 'pending']);
        $o->created_at = now();
        $o->last_checked_at = now();
        $this->assertFalse($o->isDue(now()->addMinutes(3)));
        $this->assertTrue($o->isDue(now()->addMinutes(5)));
        $o->created_at = now()->subDays(3);
        $this->assertFalse($o->isDue(now()->addMinutes(30)));
        $this->assertTrue($o->isDue(now()->addMinutes(60)));
    }

    public function test_domain_in_another_cloudflare_account_gives_a_clear_error(): void
    {
        [, $domain, $user] = $this->setUpCustomer();
        $this->cf->taken[] = 'klant.nl';

        $this->actingAs($user)->post("/mail/domains/{$domain->id}/dns/connect")
            ->assertSessionHasErrors(['connect' => 'This domain is already in another Cloudflare account. Remove it there first, or contact RHIS.']);
        $this->assertSame(0, DnsOnboarding::count());
    }

    public function test_customers_cannot_start_the_wizard_for_other_domains(): void
    {
        [, $domain] = $this->setUpCustomer();
        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'Piet', 'email' => 'p@example.test'])->id]);

        $this->actingAs($other)->get("/mail/domains/{$domain->id}/dns/connect")->assertNotFound();
        $this->actingAs($other)->post("/mail/domains/{$domain->id}/dns/connect")->assertNotFound();
    }

    public function test_proxy_switches_for_website_and_own_records_but_never_for_mail_names(): void
    {
        $this->cf->addZone('klant.nl');
        [, $domain, $user] = $this->setUpCustomer();
        $base = "/mail/domains/{$domain->id}/dns";

        $this->actingAs($user)->put("{$base}/proxy", ['proxied' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->records('klant.nl', 'A', 'klant.nl')[0]['proxied']);
        $this->actingAs($user)->put("{$base}/proxy", ['proxied' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->records('klant.nl', 'A', 'klant.nl')[0]['proxied']);
        $this->assertFalse($this->records('klant.nl', 'A', 'mail.klant.nl')[0]['proxied'], 'mail.<domain> stays DNS only');

        // Own records: mail-like names cannot be proxied.
        $this->actingAs($user)->post("{$base}/records", ['type' => 'A', 'name' => 'smtp', 'content' => '192.0.2.50', 'proxied' => '1'])->assertSessionHasErrors('proxied');
        $this->actingAs($user)->post("{$base}/records", ['type' => 'A', 'name' => 'shop', 'content' => '192.0.2.50'])->assertSessionHasNoErrors();
        $shop = $this->records('klant.nl', 'A', 'shop.klant.nl')[0];
        $this->assertFalse($shop['proxied']);

        $this->actingAs($user)->get("/mail/domains/{$domain->id}?tab=dns")->assertOk()->assertSee('DNS only')->assertSee('Cloudflare proxy for the website');

        $this->actingAs($user)->put("{$base}/records/{$shop['id']}", ['type' => 'A', 'name' => 'shop', 'content' => '192.0.2.51', 'ttl' => 300, 'proxied' => '1'])
            ->assertSessionHasNoErrors();
        $shop = $this->records('klant.nl', 'A', 'shop.klant.nl')[0];
        $this->assertSame(['192.0.2.51', true, 300], [$shop['content'], $shop['proxied'], $shop['ttl']]);

        // Managed records cannot be edited.
        $mx = $this->records('klant.nl', 'MX', 'klant.nl')[0];
        $this->actingAs($user)->put("{$base}/records/{$mx['id']}", ['type' => 'MX', 'name' => '@', 'content' => 'mx.evil.example', 'priority' => 5])->assertSessionHasErrors('dns');
        $this->assertSame('mail.rhis.nl', $this->records('klant.nl', 'MX', 'klant.nl')[0]['content']);
    }

    public function test_domains_with_own_dns_get_a_checklist(): void
    {
        [, $domain, $user] = $this->setUpCustomer();
        $domain->update(['dns_managed' => false]);
        $base = "/mail/domains/{$domain->id}";

        $this->dns->add(
            new DnsRecord('A', 'klant.nl', '192.0.2.10'),
            new DnsRecord('A', 'www.klant.nl', '203.0.113.9'),        // old host
            new DnsRecord('MX', 'klant.nl', 'mail.rhis.nl', priority: 10),
            new DnsRecord('TXT', 'klant.nl', 'v=spf1 include:oldhost.nl -all'),
        );
        $this->actingAs($user)->post("{$base}/dns/check")->assertRedirect()->assertSessionHas('warning', 'Some DNS records are still missing or wrong. See the list below.');

        $check = collect($domain->fresh()->dns_check['results'])->keyBy(fn ($r) => $r['type'] . ' ' . $r['name']);
        $this->assertSame('ok', $check['A @']['status']);
        $this->assertSame('wrong', $check['A www']['status']);
        $this->assertSame(['A 203.0.113.9'], $check['A www']['found']);
        $this->assertSame('ok', $check['MX @']['status']);
        $this->assertSame('wrong', $check['TXT @']['status']);
        $this->assertSame('missing', $check['TXT _dmarc']['status']);
        $this->assertSame('missing', $check['A mail']['status']);
        $this->assertSame('missing', $domain->fresh()->dns_status);

        $this->actingAs($user)->get("{$base}?tab=dns")->assertOk()
            ->assertSee('Records to set at your DNS provider')->assertSee('Add this record')->assertSee('Change this record')->assertSee('203.0.113.9');
        $this->actingAs($user)->get('/mail/domains')->assertSee('Own DNS, records missing');

        // Everything set: green.
        $this->dns->records = [];
        foreach (app(\Rhis\DnsMail\Services\DomainRecords::class)->all($domain->fresh()->load('website')) as $r) {
            $this->dns->add($r);
        }
        $this->travel(7)->hours();
        $this->artisan('dns-mail:check')->assertSuccessful();
        $this->assertTrue($domain->fresh()->dns_check['ok']);
        $this->assertSame('in_sync', $domain->fresh()->dns_status);
        $this->actingAs($user)->get('/mail/domains')->assertSee('Own DNS, all set');
    }

    public function test_admin_settings_show_the_account_field(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/settings/dns-mail')->assertOk()->assertSee('Account ID');
        $this->post('/admin/settings/dns-mail/test')->assertSessionHas('status')->assertSessionHas('warnings', []);
    }

    public function test_token_test_explains_failures_and_accepts_account_tokens(): void
    {
        $this->actingAsAdmin();
        $settings = $this->app->make(Settings::class);

        $settings->set('dnsmail.cloudflare_token', 'bad', encrypted: true);
        $this->post('/admin/settings/dns-mail/test')->assertSessionHasErrors('cloudflare_token');

        // Account API token: the user endpoint says "Invalid API Token", the account endpoint accepts it.
        $settings->set('dnsmail.cloudflare_token', 'acct', encrypted: true);
        $this->post('/admin/settings/dns-mail/test')->assertSessionHasNoErrors()->assertSessionHas('status');
        $settings->set('dnsmail.cloudflare_account_id', str_repeat('a', 32));
        $this->post('/admin/settings/dns-mail/test')->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'account API token'));

        // The Global API Key is refused when saving, with a clear message.
        $this->put('/admin/settings/dns-mail', ['mail_host' => 'mail.rhis.nl', 'cloudflare_token' => str_repeat('a1', 18) . 'f'])
            ->assertSessionHasErrors(['cloudflare_token' => 'This looks like the Global API Key. The panel needs an API token: Cloudflare > My Profile > API Tokens > Create Token.']);
    }
}
