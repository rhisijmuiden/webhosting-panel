<?php

namespace Tests\Feature;

use App\Services\Agent\FakeAgentClient;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Testing\FakeCloudflare;
use Rhis\Migration\Models\MigrationDnsSnapshot;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Models\MigrationSource;
use Tests\TestCase;

class MigrationWizardTest extends TestCase
{
    use RefreshDatabase;

    protected FakeCloudflare $cf;

    protected function cloudflare(array $records): void
    {
        $this->cf = new FakeCloudflare;
        $this->app->instance(HttpClient::class, $this->cf);
        $settings = $this->app->make(Settings::class);
        $settings->set('dnsmail.cloudflare_token', 'tok', encrypted: true);
        $settings->set('dnsmail.mail_host', 'mail.rhis.nl');
        $settings->set('dnsmail.ipv4', '192.0.2.10');
        $this->cf->addZone('bakkerij.test', $records);
    }

    /** Copy bakkerij.test (queue is sync in tests) and finish the copy job. */
    protected function copied(): MigrationItem
    {
        $this->actingAsAdmin();
        $source = MigrationSource::create([
            'name' => 'Plesk', 'type' => 'plesk', 'host' => 'plesk.example.test', 'port' => 22, 'user' => 'root',
            'status' => 'connected', 'inventory' => FakeAgentClient::pleskInventory(), 'inventoried_at' => now(),
        ]);
        $this->post(route('admin.migration.sources.migrate', $source), [
            'domains' => ['bakkerij.test'], 'customer' => ['bakkerij_test' => 'new'],
            'files' => 1, 'databases' => 1, 'mail' => 1, 'ssl' => 1,
        ])->assertRedirect();
        $item = MigrationItem::where('domain', 'bakkerij.test')->firstOrFail();
        $this->agent()->respond('job.status', ['id' => $item->agent_job, 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'status' => 'ok', 'errors' => [],
            'databases' => [['name' => 'wp_bakkerij', 'target' => 'wp_bakkerij', 'ok' => true, 'users' => [['user' => 'wp_bakkerij']]]],
            'mail' => [['local' => 'info', 'ok' => true], ['local' => 'jan', 'ok' => true]],
            'ssl' => ['installed' => true],
            'files' => ['stats' => ['total_size' => '120 MB', 'files' => 900]],
        ]]);
        $this->get(route('admin.migration.items.show', $item))->assertOk();

        return $item->fresh();
    }

    protected function confirmSteps(MigrationItem $item, array $steps = ['website', 'databases', 'mail', 'ssl']): void
    {
        foreach ($steps as $s) {
            $this->post(route('admin.migration.wizard.confirm', [$item, $s]))->assertSessionHasNoErrors();
        }
    }

    /** The ticked boxes of the DNS step as the page shows them. @return list<string> */
    protected function tickedKeys(MigrationItem $item): array
    {
        $html = $this->get(route('admin.migration.wizard.show', [$item, 'dns']))->assertOk()->getContent();
        preg_match_all('/name="keys\[\]" value="([0-9a-f]{16})"[^>]*checked/', $html, $m);

        return $m[1];
    }

    protected function records(): \Illuminate\Support\Collection
    {
        return collect($this->cf->records('bakkerij.test'));
    }

    public function test_wizard_walks_through_the_steps_and_switches_only_the_ticked_records(): void
    {
        $this->cloudflare([
            ['type' => 'A', 'name' => 'bakkerij.test', 'content' => '203.0.113.5', 'proxied' => false, 'ttl' => 1],
            ['type' => 'MX', 'name' => 'bakkerij.test', 'content' => 'bakkerij-test.mail.protection.outlook.com', 'priority' => 0, 'ttl' => 1],
            ['type' => 'TXT', 'name' => 'bakkerij.test', 'content' => 'MS=ms123456', 'ttl' => 1],
        ]);
        $item = $this->copied();
        $this->assertSame('copied', $item->status);

        // The wizard opens on the first step that is not confirmed.
        $this->get(route('admin.migration.wizard.show', $item))->assertOk()
            ->assertSee('Migration wizard')->assertSee('Website files copied (120 MB).');
        $this->get(route('admin.migration.wizard.show', [$item, 'mail']))->assertOk()->assertSee('Microsoft 365');

        // DNS: the web record changes; the Microsoft 365 MX is shown but not ticked.
        $dns = $this->get(route('admin.migration.wizard.show', [$item, 'dns']))->assertOk()
            ->assertSee('bakkerij-test.mail.protection.outlook.com')->assertSee('Points to Microsoft 365. Kept unless you tick it.')
            ->assertSee('google-site-verification=abc123');
        $keys = $this->tickedKeys($item);
        $this->assertNotEmpty($keys);

        // Nothing in Cloudflare changed by looking.
        $this->assertSame('203.0.113.5', $this->records()->firstWhere('type', 'A')['content']);

        // Not possible before every step is confirmed.
        $this->post(route('admin.migration.wizard.switch', $item), ['understood' => 1, 'confirm_domain' => 'bakkerij.test'])
            ->assertSessionHasErrors('wizard');

        $this->confirmSteps($item);
        $this->post(route('admin.migration.wizard.confirm', [$item, 'dns']), ['keys' => $keys, 'total' => 99])->assertSessionHasNoErrors();
        $this->get(route('admin.migration.wizard.show', [$item, 'final']))->assertOk()->assertSee('Switch DNS definitively');

        // Typed confirmation is required.
        $this->post(route('admin.migration.wizard.switch', $item), ['understood' => 1, 'confirm_domain' => 'other.test'])
            ->assertSessionHasErrors('confirm_domain');
        $this->assertSame('203.0.113.5', $this->records()->firstWhere('type', 'A')['content']);

        $this->post(route('admin.migration.wizard.switch', $item), ['understood' => 1, 'confirm_domain' => 'bakkerij.test'])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('live', $item->status);
        $records = $this->records();
        $this->assertSame('192.0.2.10', $records->where('type', 'A')->firstWhere('name', 'bakkerij.test')['content']);
        $this->assertSame(['bakkerij-test.mail.protection.outlook.com'], $records->where('type', 'MX')->pluck('content')->all(), 'M365 MX was kept');
        $this->assertTrue($records->contains(fn ($r) => $r['type'] === 'TXT' && $r['content'] === 'MS=ms123456'));
        $this->assertTrue($records->contains(fn ($r) => $r['type'] === 'TXT' && $r['content'] === 'google-site-verification=abc123'));
        $this->assertTrue($records->contains(fn ($r) => $r['type'] === 'CNAME' && $r['name'] === 'shop.bakkerij.test'));
        $this->assertTrue(MailDomain::where('domain', 'bakkerij.test')->value('dns_managed'));
        $this->assertSame('cloudflare', $item->result['dns']['mode']);
        $this->assertGreaterThan(0, $item->result['dns']['kept']);

        $snapshot = MigrationDnsSnapshot::firstOrFail();
        $this->assertCount(3, $snapshot->records);
        $this->assertSame($snapshot->id, $item->result['dns']['snapshot']);
        $this->get(route('admin.migration.wizard.show', [$item, 'final']))->assertOk()->assertSee('DNS switched')->assertSee('Put old DNS back');
    }

    public function test_snapshot_puts_the_old_records_back(): void
    {
        $this->cloudflare([
            ['type' => 'A', 'name' => 'bakkerij.test', 'content' => '203.0.113.5', 'proxied' => false, 'ttl' => 1],
            ['type' => 'MX', 'name' => 'bakkerij.test', 'content' => 'mail.bakkerij.test', 'priority' => 10, 'ttl' => 1],
        ]);
        $item = $this->copied();
        $this->confirmSteps($item);
        $keys = $this->tickedKeys($item);
        $this->post(route('admin.migration.wizard.confirm', [$item, 'dns']), ['keys' => $keys, 'total' => count($keys)]);
        $this->post(route('admin.migration.wizard.switch', $item), ['understood' => 1, 'confirm_domain' => 'bakkerij.test'])->assertSessionHasNoErrors();
        $this->assertSame('mail.rhis.nl', $this->records()->firstWhere('type', 'MX')['content']);
        $before = $this->records()->count();
        $this->assertGreaterThan(2, $before);

        $snapshot = MigrationDnsSnapshot::firstOrFail();
        $this->post(route('admin.migration.wizard.rollback', [$item, $snapshot]), ['sure' => 1])->assertSessionHasNoErrors();

        $records = $this->records();
        $this->assertSame(['203.0.113.5'], $records->where('type', 'A')->where('name', 'bakkerij.test')->pluck('content')->all());
        $this->assertSame(['mail.bakkerij.test'], $records->where('type', 'MX')->pluck('content')->all());
        $this->assertFalse($records->contains(fn ($r) => str_contains((string) $r['content'], 'google-site-verification')), 'created records are removed');
        $this->assertCount(2, $records);
        $item->refresh();
        $this->assertSame('copied', $item->status);
        $this->assertFalse(MailDomain::where('domain', 'bakkerij.test')->value('dns_managed'));
        $this->assertNotNull($snapshot->fresh()->rolled_back_at);

        // A snapshot is put back only once.
        $this->post(route('admin.migration.wizard.rollback', [$item, $snapshot]))->assertSessionHasErrors('wizard');
    }

    public function test_switch_stops_when_cloudflare_changed_after_the_choice(): void
    {
        $this->cloudflare([
            ['type' => 'A', 'name' => 'bakkerij.test', 'content' => '203.0.113.5', 'proxied' => false, 'ttl' => 1],
        ]);
        $item = $this->copied();
        $this->confirmSteps($item);
        $keys = $this->tickedKeys($item);
        $this->post(route('admin.migration.wizard.confirm', [$item, 'dns']), ['keys' => $keys, 'total' => count($keys)]);

        // Someone changes the A record in Cloudflare in the meantime.
        $zone = $this->cf->zoneByName('bakkerij.test');
        $id = $this->records()->firstWhere('type', 'A')['id'];
        app(\Rhis\DnsMail\Services\DnsSync::class)->client()->updateRecord($zone['id'], $id,
            new \Rhis\DnsMail\Dns\DnsRecord('A', 'bakkerij.test', '203.0.113.99'));

        $this->get(route('admin.migration.wizard.show', [$item, 'final']))->assertOk()->assertSee('changed since you made your choice');
        $this->post(route('admin.migration.wizard.switch', $item), ['understood' => 1, 'confirm_domain' => 'bakkerij.test'])
            ->assertSessionHasErrors('wizard');
        $this->assertSame('203.0.113.99', $this->records()->firstWhere('type', 'A')['content']);
        $this->assertSame('copied', $item->fresh()->status);
        $this->assertSame(0, MigrationDnsSnapshot::count());
    }

    public function test_keeping_mail_external_leaves_mail_records_out_of_the_diff(): void
    {
        $this->cloudflare([
            ['type' => 'MX', 'name' => 'bakkerij.test', 'content' => 'bakkerij-test.mail.protection.outlook.com', 'priority' => 0, 'ttl' => 1],
        ]);
        $item = $this->copied();
        $this->post(route('admin.migration.wizard.mail', $item), ['provider' => 'external'])->assertSessionHasNoErrors();
        $this->assertSame('external', MailDomain::where('domain', 'bakkerij.test')->value('provider'));

        $this->get(route('admin.migration.wizard.show', [$item, 'mail']))->assertOk()->assertSee('Mail stays at the current provider');
        $html = $this->get(route('admin.migration.wizard.show', [$item, 'dns']))->assertOk()->getContent();
        // The MX is only listed under the records that stay.
        $this->assertStringNotContainsString('Points to Microsoft 365', $html);
        $this->assertStringContainsString('bakkerij-test.mail.protection.outlook.com', $html);
    }

    public function test_website_test_asks_this_server(): void
    {
        $this->cloudflare([]);
        $item = $this->copied();
        Http::fake(['https://bakkerij.test/*' => Http::response('ok', 200)]);

        $this->post(route('admin.migration.wizard.test', $item))->assertRedirect();
        $this->assertSame(200, $item->fresh()->wizardValue('http.status'));
        $this->get(route('admin.migration.wizard.show', [$item, 'website']))->assertOk()->assertSee('The website answers with HTTP 200 on this server.');
    }

    public function test_without_a_zone_the_wizard_offers_to_add_the_domain_to_cloudflare(): void
    {
        $this->cloudflare([]);
        $this->cf = new FakeCloudflare; // no zone for bakkerij.test
        $this->cf->accounts = [['id' => 'acc1', 'name' => 'RHIS']];
        $this->app->instance(HttpClient::class, $this->cf);
        $item = $this->copied();

        $this->get(route('admin.migration.wizard.show', [$item, 'dns']))->assertOk()->assertSee('Add domain to Cloudflare');
        $this->post(route('admin.migration.wizard.zone', $item))->assertSessionHasNoErrors();
        $this->assertNotNull($this->cf->zoneByName('bakkerij.test'));
        $this->assertNotNull(MailDomain::where('domain', 'bakkerij.test')->value('cloudflare_zone_id'));
        $this->assertFalse(MailDomain::where('domain', 'bakkerij.test')->value('dns_managed'), 'nothing switched yet');
        $this->get(route('admin.migration.wizard.show', [$item, 'dns']))->assertOk()->assertSee('What changes in Cloudflare');
    }
}
