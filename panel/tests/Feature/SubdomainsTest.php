<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DomainRecords;
use Rhis\Subdomains\Models\SiteClone;
use Tests\TestCase;

class SubdomainsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $domain = 'klant.nl', string $email = 'jan@klant.nl'): array
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => $email]);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => $domain, 'status' => 'active', 'php_version' => '8.3', 'profile' => 'wordpress', 'aliases' => ['www.'.$domain]]);

        return [$customer, $user, $site];
    }

    public function test_client_adds_a_subdomain_that_shares_the_parent(): void
    {
        [, $user, $site] = $this->customer();

        $this->actingAs($user)->get("/websites/{$site->id}?tab=hosting")->assertOk()
            ->assertSee(__('Subdomains'))->assertSee(__('Add subdomain'))->assertSee('.klant.nl');
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'Test', 'profile' => 'wordpress'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $sub = Website::where('domain', 'test.klant.nl')->sole();
        $this->assertSame([$site->id, $site->customer_id, 'active', '8.3', 'wordpress'], [$sub->parent_id, $sub->customer_id, $sub->status, $sub->php_version, $sub->profile]);
        $this->assertTrue($sub->isSubdomain());
        $call = collect($this->agent()->calls)->where('action', 'site.add')->last();
        $this->assertSame('klant.nl', $call['params']['parent']);
        $this->assertTrue($call['params']['no_alias'], 'no www. for a subdomain');
        $this->assertArrayNotHasKey('quota_mb', $call['params']);

        // No mail domain of its own; its A record goes into the main domain's zone.
        $this->assertFalse(MailDomain::where('domain', 'test.klant.nl')->exists());
        $main = MailDomain::where('domain', 'klant.nl')->firstOrFail();
        app(\App\Services\Settings::class)->set('dnsmail.ipv4', '192.0.2.10');
        $names = collect(app(DomainRecords::class)->web($main))->pluck('name')->unique()->values()->all();
        $this->assertContains('test.klant.nl', $names);

        $this->actingAs($user)->get('/websites')->assertOk()->assertSee('test.klant.nl')->assertSee(__('Subdomain of :domain', ['domain' => 'klant.nl']));
        $this->actingAs($user)->get("/websites/{$sub->id}?tab=hosting")->assertOk()
            ->assertSee(__('This is a subdomain of :domain. It is part of that subscription: disk space and limits are shared.', ['domain' => 'klant.nl']))
            ->assertDontSee(__('Add subdomain'));
    }

    public function test_bad_reserved_and_taken_names_are_refused(): void
    {
        [, $user, $site] = $this->customer();
        [, , $other] = $this->customer('ander.nl', 'piet@ander.nl');
        $other->customer->websites()->create(['domain' => 'shop.klant.nl', 'status' => 'active', 'php_version' => '8.4']);

        foreach (['-bad', 'a.b', 'www', 'mail', 'shop', str_repeat('x', 64)] as $label) {
            $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => $label])->assertSessionHasErrors('label');
        }
        $this->assertSame(0, $site->subdomains()->count());

        // Never on another customer's website, and never a subdomain of a subdomain.
        $this->actingAs($user)->post("/websites/{$other->id}/subdomains", ['label' => 'test'])->assertNotFound();
        $sub = $site->customer->websites()->create(['domain' => 'dev.klant.nl', 'status' => 'active', 'php_version' => '8.4']);
        $sub->parent_id = $site->id;
        $sub->save();
        $this->actingAs($user)->post("/websites/{$sub->id}/subdomains", ['label' => 'x'])->assertSessionHasErrors('label');
    }

    public function test_the_admin_can_limit_and_switch_off_customer_subdomains(): void
    {
        [, $user, $site] = $this->customer();
        config(['subdomains.max_per_domain' => 1]);
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'een'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'twee'])->assertSessionHasErrors('label');

        config(['subdomains.customer_manage' => false]);
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'drie'])->assertForbidden();
    }

    public function test_removing_a_subdomain_needs_its_name_and_drops_its_databases(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'test']);
        $sub = Website::where('domain', 'test.klant.nl')->sole();
        SiteDatabase::create(['website_id' => $sub->id, 'name' => 'test_klant_nl_clone_ab12', 'users' => ['test_klant_nl_clone_ab12'], 'origin' => 'clone']);

        $this->actingAs($user)->delete("/websites/{$site->id}/subdomains/{$sub->id}", ['confirm' => 'klant.nl'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->delete("/websites/{$site->id}/subdomains/{$site->id}", ['confirm' => 'klant.nl'])->assertNotFound();
        $this->assertTrue(Website::whereKey($sub->id)->exists());

        $this->actingAs($user)->delete("/websites/{$site->id}/subdomains/{$sub->id}", ['confirm' => 'test.klant.nl'])->assertSessionHasNoErrors();
        $this->assertFalse(Website::whereKey($sub->id)->exists());
        $this->assertSame(0, SiteDatabase::count());
        $drop = collect($this->agent()->calls)->firstWhere('action', 'db.drop');
        $this->assertSame(['name' => 'test_klant_nl_clone_ab12', 'domain' => 'test.klant.nl'], $drop['params']);
    }

    public function test_client_clones_wordpress_into_a_new_subdomain(): void
    {
        [, $user, $site] = $this->customer();
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => ['done'], 'error' => null, 'result' => [
            'source' => 'klant.nl', 'target' => 'test.klant.nl', 'url' => 'https://test.klant.nl', 'size_mb' => 184,
            'installs' => [['kind' => 'wordpress', 'path' => '', 'database' => 'test_klant_nl_clone_9f3c', 'url' => 'https://test.klant.nl', 'replacements' => 412]],
            'databases' => ['test_klant_nl_clone_9f3c'], 'dropped_databases' => [],
        ]]);

        $this->actingAs($user)->get("/websites/{$site->id}/clone")->assertOk()
            ->assertSee(__('Clone :domain', ['domain' => 'klant.nl']))->assertSee(__('A new subdomain'))->assertSee('example_wp_1a2b');
        $this->actingAs($user)->post("/websites/{$site->id}/clone", ['target' => 'new', 'new_label' => 'test', 'noindex' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $clone = SiteClone::sole();
        $sub = Website::where('domain', 'test.klant.nl')->sole();
        $this->assertSame([$site->id, $sub->id, 'wordpress', '8.3'], [$sub->parent_id, $clone->target_website_id, $sub->profile, $sub->php_version]);
        $call = collect($this->agent()->calls)->firstWhere('action', 'site.clone');
        $this->assertSame(['source' => 'klant.nl', 'target' => 'test.klant.nl', 'replace' => false, 'noindex' => true], $call['params']);

        $this->actingAs($user)->get("/clones/{$clone->id}")->assertOk()->assertSee(__('The copy is ready'))->assertSee('test_klant_nl_clone_9f3c');
        $this->assertSame('succeeded', $clone->fresh()->status);
        $db = SiteDatabase::where('name', 'test_klant_nl_clone_9f3c')->sole();
        $this->assertSame([$sub->id, 'clone'], [$db->website_id, $db->origin]);
    }

    public function test_cloning_over_an_existing_website_needs_replace_and_confirmation(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->post("/websites/{$site->id}/subdomains", ['label' => 'test']);
        $sub = Website::where('domain', 'test.klant.nl')->sole();
        $this->agent()->respond('site.clone_plan', fn (array $p) => ['source' => $p['source'], 'target' => $p['target'] ?? null,
            'installs' => [], 'target_content' => isset($p['target']) ? ['index.php', 'wp-content'] : [], 'size_mb' => 10]);

        // Copy the test site back over the live one.
        $this->actingAs($user)->get("/websites/{$sub->id}/clone?target={$site->id}")->assertOk()->assertSee('index.php, wp-content');
        $this->actingAs($user)->post("/websites/{$sub->id}/clone", ['target' => (string) $site->id])->assertSessionHasErrors('replace');
        $this->actingAs($user)->post("/websites/{$sub->id}/clone", ['target' => (string) $site->id, 'replace' => '1', 'confirm' => 'test.klant.nl'])->assertSessionHasErrors('confirm');
        $this->assertSame(0, SiteClone::count());

        $this->actingAs($user)->post("/websites/{$sub->id}/clone", ['target' => (string) $site->id, 'replace' => '1', 'confirm' => 'klant.nl'])->assertSessionHasNoErrors();
        $call = collect($this->agent()->calls)->firstWhere('action', 'site.clone');
        $this->assertSame(['source' => 'test.klant.nl', 'target' => 'klant.nl', 'replace' => true, 'noindex' => false], $call['params']);
    }

    public function test_clone_only_within_the_same_customer_and_failures_are_shown(): void
    {
        [, $user, $site] = $this->customer();
        [, $other, $foreign] = $this->customer('ander.nl', 'piet@ander.nl');

        $this->actingAs($user)->post("/websites/{$site->id}/clone", ['target' => (string) $foreign->id])->assertSessionHasErrors('target');
        $this->actingAs($user)->get("/websites/{$foreign->id}/clone")->assertNotFound();
        $this->assertSame(0, SiteClone::count());

        $this->agent()->respond('site.clone', new AgentException('conflict', 'A clone of one of these websites is already running'));
        $this->actingAs($user)->post("/websites/{$site->id}/clone", ['target' => 'new', 'new_label' => 'staging'])->assertRedirect();
        $clone = SiteClone::sole();
        $this->assertSame('failed', $clone->status);
        $this->actingAs($user)->get("/clones/{$clone->id}")->assertOk()->assertSee('already running');
        $this->actingAs($other)->get("/clones/{$clone->id}")->assertNotFound();
    }

    public function test_admin_sees_subdomains_and_clone_on_the_website_page(): void
    {
        [, , $site] = $this->customer();
        $this->actingAsAdmin();
        $this->post("/admin/websites/{$site->id}/subdomains", ['label' => 'dev'])->assertSessionHasNoErrors();
        $this->get("/admin/websites/{$site->id}")->assertOk()->assertSee('dev.klant.nl')->assertSee(__('Clone website'));
        $this->get("/admin/websites/{$site->id}/clone")->assertOk()->assertSee('dev.klant.nl');
    }

    public function test_domain_page_tools_have_clone_and_subdomain_tiles(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=tools")->assertOk()
            ->assertSee(__('Clone website'))->assertSee(__('Test copy on a subdomain'))->assertSee("/websites/{$site->id}/clone", false);
    }

    public function test_tick_starts_a_clone_once_the_subdomain_is_ready(): void
    {
        [, $user, $site] = $this->customer();
        $sub = $site->customer->websites()->create(['domain' => 'test.klant.nl', 'status' => 'provisioning', 'php_version' => '8.4']);
        $clone = SiteClone::create(['customer_id' => $site->customer_id, 'source_website_id' => $site->id, 'target_website_id' => $sub->id,
            'source_domain' => 'klant.nl', 'target_domain' => 'test.klant.nl', 'status' => 'waiting']);
        $this->artisan('site-clone:tick')->assertSuccessful();
        $this->assertSame('waiting', $clone->fresh()->status);

        $sub->update(['status' => 'active']);
        $this->artisan('site-clone:tick')->assertSuccessful();
        $this->assertSame('succeeded', $clone->fresh()->status);
    }
}
