<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\FakeAgentClient;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Testing\FakeCloudflare;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Models\MigrationSource;
use Rhis\Migration\Services\DnsCutover;
use Rhis\Migration\Services\SitePlan;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    private function source(): MigrationSource
    {
        return MigrationSource::create([
            'name' => 'Plesk', 'type' => 'plesk', 'host' => 'plesk.example.test', 'port' => 22, 'user' => 'root',
            'status' => 'connected', 'inventory' => FakeAgentClient::pleskInventory(), 'inventoried_at' => now(),
        ]);
    }

    private function calls(string $action): array
    {
        return array_values(array_filter($this->agent()->calls, fn ($c) => $c['action'] === $action));
    }

    /** Queue bakkerij.test and run the preparation (queue is sync in tests). */
    private function migrateBakkerij(MigrationSource $source): MigrationItem
    {
        $this->post(route('admin.migration.sources.migrate', $source), [
            'domains' => ['bakkerij.test'], 'customer' => ['bakkerij_test' => 'new'],
            'files' => 1, 'databases' => 1, 'mail' => 1, 'ssl' => 1,
        ])->assertRedirect();

        return MigrationItem::where('domain', 'bakkerij.test')->firstOrFail();
    }

    private function finishJob(MigrationItem $item, array $result): void
    {
        $this->agent()->respond('job.status', ['id' => $item->agent_job, 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => $result]);
        $this->get(route('admin.migration.items.show', $item))->assertOk();
    }

    public function test_index_shows_key_and_menu(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/migration')->assertOk()
            ->assertSee('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForDevelopmentOnly')
            ->assertSee(route('admin.migration.sources.store'), false);
        $this->get('/admin')->assertSee(route('admin.migration.index'), false);
    }

    public function test_adding_a_source_tests_the_connection(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/migration/sources', ['name' => 'Plesk', 'type' => 'plesk', 'host' => 'plesk.example.test', 'port' => 22, 'user' => 'root'])
            ->assertRedirect();
        $source = MigrationSource::firstOrFail();
        $this->assertSame('connected', $source->status);
        $this->assertSame('18.0.74', $source->version);
        $this->assertSame(['type' => 'plesk', 'host' => 'plesk.example.test', 'port' => 22, 'user' => 'root'], $this->calls('migrate.connect')[0]['params']);

        $this->post('/admin/migration/sources', ['name' => 'x', 'type' => 'plesk', 'host' => 'bad host;rm', 'port' => 22, 'user' => 'root'])->assertSessionHasErrors('host');
        $this->post('/admin/migration/sources', ['name' => 'x', 'type' => 'cpanel', 'host' => 'a.test', 'port' => 22, 'user' => 'root'])->assertSessionHasErrors('type');
    }

    public function test_inventory_job_result_is_stored_and_listed(): void
    {
        $this->actingAsAdmin();
        $source = $this->source();
        $source->update(['inventory' => null]);

        $this->post(route('admin.migration.sources.inventory', $source))->assertRedirect();
        $this->assertNotNull($source->fresh()->inventory_job);

        $this->agent()->respond('job.status', ['id' => 'x', 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => FakeAgentClient::pleskInventory()]);
        $this->get(route('admin.migration.sources.show', $source))->assertOk()
            ->assertSee('bakkerij.test')->assertSee('api.bakkerij.test')->assertSee('Bakkerij Bakker');
        $this->assertNull($source->fresh()->inventory_job);
        $this->assertSame(2, $source->fresh()->inventory['totals']['domains']);
    }

    public function test_migrate_creates_customer_website_mailboxes_and_starts_the_copy(): void
    {
        $this->actingAsAdmin();
        $item = $this->migrateBakkerij($this->source());

        $this->assertSame('copying', $item->status);
        $customer = Customer::where('email', 'piet@bakkerij.test')->firstOrFail();
        $this->assertSame('Bakkerij Bakker', $customer->company);

        $site = Website::where('domain', 'bakkerij.test')->firstOrFail();
        $this->assertSame('wordpress', $site->profile);
        $this->assertSame('8.3', $site->php_version, 'PHP 8.2 moves up to the lowest supported version');
        $this->assertSame('active', $site->status);
        $add = $this->calls('site.add')[0]['params'];
        $this->assertSame('public', $add['docroot']);
        $this->assertFalse($add['ssl']);
        $this->assertSame(['www.bakkerij.test', 'bakkerij-bakker.test', 'www.bakkerij-bakker.test'], $add['aliases']);

        // DNS stays as it is until the switch.
        $domain = MailDomain::where('domain', 'bakkerij.test')->firstOrFail();
        $this->assertFalse($domain->dns_managed);
        $boxes = $domain->mailboxes()->get()->keyBy('local_part');
        $this->assertTrue(password_verify('Geheim-123', substr($boxes['info']->password_hash, strlen('{ARGON2ID}'))));
        $this->assertStringStartsWith('{SHA512-CRYPT}$6$', $boxes['jan']->password_hash);
        $this->assertSame(1024, $boxes['info']->quota_mb);
        $this->assertSame(['info@bakkerij.test'], MailAlias::where('local_part', 'bestellingen')->first()->targets);
        $this->assertSame(['boekhouder@example.org'], MailAlias::where('local_part', 'factuur')->first()->targets);
        $this->assertNotEmpty($this->calls('mail.mailboxes_sync'));

        $transfer = $this->calls('migrate.transfer')[0]['params'];
        $this->assertSame(['target' => 'public', 'delete' => true], $transfer['files']);
        $this->assertSame([['name' => 'wp_bakkerij', 'target' => 'wp_bakkerij', 'users' => ['wp_bakkerij']]], $transfer['databases']);
        $this->assertSame(['mode' => 'mirror', 'mailboxes' => ['info', 'jan']], $transfer['mail']);
        $this->assertTrue($transfer['ssl']);
        $this->assertSame('plesk.example.test', $transfer['host']);
        $this->assertContains('PHP 8.2 on the old server, now 8.3. Test the website before switching DNS.', $item->noteTexts());
    }

    public function test_finished_copy_records_databases_and_new_passwords(): void
    {
        $this->actingAsAdmin();
        $item = $this->migrateBakkerij($this->source());

        $this->finishJob($item, [
            'domain' => 'bakkerij.test', 'status' => 'ok', 'errors' => [], 'files' => ['stats' => ['files' => '1,204', 'total_size' => '388M']],
            'databases' => [['name' => 'wp_bakkerij', 'target' => 'wp_bakkerij', 'ok' => true, 'users' => [['user' => 'wp_bakkerij', 'password_kept' => false, 'new_password' => 'NieuwWachtwoord']]]],
            'mail' => [['local' => 'info', 'ok' => true, 'stats' => []], ['local' => 'jan', 'ok' => true, 'stats' => []]],
            'ssl' => ['installed' => true],
        ]);

        $item->refresh();
        $this->assertSame('copied', $item->status);
        $this->assertNotNull($item->copied_at);
        $this->assertSame(['db:wp_bakkerij' => 'NieuwWachtwoord'], $item->secrets);
        $db = SiteDatabase::where('name', 'wp_bakkerij')->firstOrFail();
        $this->assertSame($item->website_id, $db->website_id);
        $this->assertSame('imported', $item->website->fresh()->ssl_status);
        $this->get(route('admin.migration.items.show', $item))->assertSee('NieuwWachtwoord')->assertSee('388M');

        // Sync: everything again, no certificate.
        $this->post(route('admin.migration.items.sync', $item), ['kind' => 'sync'])->assertRedirect();
        $sync = $this->calls('migrate.transfer')[1]['params'];
        $this->assertFalse($sync['ssl']);
        $this->assertSame('mirror', $sync['mail']['mode']);
        $this->assertSame('syncing', $item->fresh()->status);

        // Late mail is only for after the switch.
        $this->post(route('admin.migration.items.sync', $item), ['kind' => 'late_mail'])->assertSessionHasErrors('agent');

        $this->post(route('admin.migration.items.finish', $item->fresh()));
        $this->assertNotSame('done', $item->fresh()->status, 'cannot finish while syncing');
    }

    public function test_dns_switch_takes_over_own_records_in_cloudflare(): void
    {
        $cf = new FakeCloudflare;
        $this->app->instance(HttpClient::class, $cf);
        $settings = $this->app->make(Settings::class);
        $settings->set('dnsmail.cloudflare_token', 'tok', encrypted: true);
        $settings->set('dnsmail.mail_host', 'mail.rhis.nl');
        $settings->set('dnsmail.ipv4', '192.0.2.10');
        $cf->addZone('bakkerij.test', [
            ['type' => 'A', 'name' => 'bakkerij.test', 'content' => '203.0.113.5', 'proxied' => false, 'ttl' => 1],
            ['type' => 'MX', 'name' => 'bakkerij.test', 'content' => 'mail.bakkerij.test', 'priority' => 10, 'ttl' => 1],
        ]);

        $this->actingAsAdmin();
        $item = $this->migrateBakkerij($this->source());
        $this->assertSame('203.0.113.5', collect($cf->records('bakkerij.test'))->firstWhere('type', 'A')['content'], 'no DNS change while copying');
        $this->finishJob($item, ['status' => 'ok', 'errors' => [], 'databases' => [], 'mail' => [], 'ssl' => null]);

        $this->post(route('admin.migration.items.cutover', $item))->assertSessionHasErrors('confirm');
        $this->post(route('admin.migration.items.cutover', $item), ['confirm' => 1])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('live', $item->status);
        $records = collect($cf->records('bakkerij.test'));
        $this->assertSame('192.0.2.10', $records->where('type', 'A')->firstWhere('name', 'bakkerij.test')['content']);
        $this->assertSame('mail.rhis.nl', $records->firstWhere('type', 'MX')['content']);
        $this->assertTrue($records->contains(fn ($r) => $r['type'] === 'TXT' && $r['content'] === 'google-site-verification=abc123'));
        $this->assertTrue($records->contains(fn ($r) => $r['type'] === 'CNAME' && $r['name'] === 'shop.bakkerij.test'));
        $this->assertFalse($records->contains(fn ($r) => $r['type'] === 'TXT' && str_contains($r['content'], '+a +mx')));
        $this->assertTrue(MailDomain::where('domain', 'bakkerij.test')->value('dns_managed'));
        $this->assertSame(2, $item->result['dns']['imported']);

        // After the switch: fetch late mail only.
        $this->post(route('admin.migration.items.sync', $item), ['kind' => 'late_mail'])->assertSessionHasNoErrors();
        $late = collect($this->calls('migrate.transfer'))->last()['params'];
        $this->assertSame('add', $late['mail']['mode']);
        $this->assertArrayNotHasKey('files', $late);
        $this->assertArrayNotHasKey('databases', $late);
    }

    public function test_dns_switch_without_cloudflare_gives_a_zone_file(): void
    {
        $this->actingAsAdmin();
        $item = $this->migrateBakkerij($this->source());
        $this->finishJob($item, ['status' => 'ok', 'errors' => [], 'databases' => [], 'mail' => [], 'ssl' => null]);

        $this->post(route('admin.migration.items.cutover', $item), ['confirm' => 1])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('manual', $item->result['dns']['mode']);
        $this->assertStringContainsString('google-site-verification=abc123', $item->result['dns']['zone']);
        $this->assertStringContainsString('$ORIGIN bakkerij.test.', $item->result['dns']['zone']);
        $this->get(route('admin.migration.items.show', $item))->assertOk()->assertSee('$ORIGIN bakkerij.test.');
    }

    public function test_parallel_limit_keeps_domains_queued(): void
    {
        config(['migration.parallel' => 1]);
        $this->actingAsAdmin();
        $source = $this->source();
        $this->post(route('admin.migration.sources.migrate', $source), [
            'domains' => ['bakkerij.test', 'api.bakkerij.test'], 'files' => 1, 'databases' => 1, 'mail' => 1, 'ssl' => 1,
        ]);

        $this->assertSame('copying', MigrationItem::where('domain', 'bakkerij.test')->value('status'));
        $this->assertSame('queued', MigrationItem::where('domain', 'api.bakkerij.test')->value('status'));
        // Same Plesk subscription, same customer.
        $item = MigrationItem::where('domain', 'bakkerij.test')->first();
        $this->finishJob($item, ['status' => 'ok', 'errors' => [], 'databases' => [], 'mail' => [], 'ssl' => null]);
        $this->artisan('migration:tick')->assertSuccessful();
        $api = MigrationItem::where('domain', 'api.bakkerij.test')->first();
        $this->assertContains($api->status, ['copying', 'copied'], 'started once a slot was free');
        $this->assertSame($item->customer_id, $api->customer_id);
        $this->assertSame('app/public', $this->calls('site.add')[1]['params']['docroot']);
        $this->assertSame('laravel', $api->website->profile);
        $this->assertSame('app', $this->calls('migrate.transfer')[1]['params']['files']['target']);
    }

    public function test_existing_customer_can_be_chosen_and_failures_are_shown(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Bestaand', 'email' => 'b@example.test']);
        $this->agent()->respond('site.add', new \App\Services\Agent\AgentException('command_failed', 'whp-site exited with code 1'));
        $source = $this->source();

        $this->post(route('admin.migration.sources.migrate', $source), [
            'domains' => ['bakkerij.test'], 'customer' => ['bakkerij_test' => (string) $customer->id], 'files' => 1,
        ]);
        $item = MigrationItem::firstOrFail();
        $this->assertSame('failed', $item->status);
        $this->assertSame($customer->id, $item->customer_id);
        $this->assertStringContainsString('whp-site exited', $item->error);
        $this->get(route('admin.migration.items.show', $item))->assertOk()->assertSee('whp-site exited');
    }

    public function test_site_plan_and_dns_filter(): void
    {
        $inv = FakeAgentClient::pleskInventory();
        $plan = SitePlan::for($inv['domains'][1]);
        $this->assertSame(['laravel', 'app/public', 'app'], [$plan['profile'], $plan['docroot'], $plan['target']]);
        $this->assertSame('8.4', $plan['php']);
        $this->assertSame(['8.5', null], SitePlan::php('9.1', '8.3'));
        $this->assertSame('{SHA256-CRYPT}$5$x$y', SitePlan::mailHash('crypt', '$5$x$y'));
        $this->assertNull(SitePlan::mailHash('none', ''));

        $d = $inv['domains'][0] + ['server_ips' => $inv['server_ips']];
        [$keep, $skipped] = DnsCutover::importable($d, [new DnsRecord('A', 'bakkerij.test', '192.0.2.10')]);
        $this->assertSame(['TXT google-site-verification=abc123', 'CNAME shops.myshopify.com'], array_map(fn ($r) => $r->type.' '.$r->content, $keep));
        $this->assertCount(3, $skipped);
    }
}
