<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Models\BulkRunItem;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\Models\IntegrityReport;
use Tests\TestCase;

/** Bulk actions of the other WordPress features (extensions, switches, integrity). */
class WordPressBulkIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wordpress.bulk.poll_seconds' => 0]);
    }

    private function sites(): array
    {
        $c = Customer::create(['name' => 'Alfa', 'email' => 'info@alfa.nl']);
        foreach (['alfa.nl', 'bravo.nl'] as $domain) {
            Website::create(['customer_id' => $c->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);
        }
        $this->artisan('whp:wordpress:scan')->assertSuccessful();
        $installs = WordPressInstall::with('website')->get()->keyBy(fn ($i) => $i->website->domain);
        // bravo.nl has no Hello Dolly.
        $installs['bravo.nl']->update(['plugins' => collect($installs['bravo.nl']->plugins)->reject(fn ($p) => $p['name'] === 'hello-dolly')->values()->all()]);

        return $installs->all();
    }

    private function bulk(string $action, array $ids, array $options = [], array $extra = []): BulkRun
    {
        $res = $this->post('/admin/wordpress/bulk', ['action' => $action, 'ids' => $ids, 'options' => [$action => $options]] + $extra);
        $res->assertRedirect();
        $this->assertMatchesRegularExpression('#/runs/\d+$#', $res->headers->get('Location'));

        return BulkRun::findOrFail((int) basename($res->headers->get('Location')));
    }

    private function items(BulkRun $run): array
    {
        return $run->items()->get()->mapWithKeys(fn (BulkRunItem $i) => [$i->label => [$i->status, $i->message]])->all();
    }

    private function ids(array $s): array
    {
        return array_map(fn ($i) => $i->id, array_values($s));
    }

    public function test_actions_are_offered(): void
    {
        $this->sites();
        $this->actingAsAdmin();
        $this->get('/admin/wordpress/bulk')->assertOk()
            ->assertSee('Switch plugins or themes')->assertSee('Install a plugin or theme')->assertSee('Delete plugins or themes')
            ->assertSee('Clear all caches')->assertSee('Maintenance mode')->assertSee('Debug mode')->assertSee('Search engine indexing')
            ->assertSee('File integrity check');
    }

    public function test_plugin_activate_deactivate_and_delete(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->bulk('plugins-manage', $this->ids($s), ['type' => 'plugin', 'op' => 'activate', 'slugs' => 'hello-dolly']);
        $this->assertSame(['alfa.nl' => ['succeeded', 'Done: hello-dolly'], 'bravo.nl' => ['skipped', 'Not installed on this site.']], $this->items($run));
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.ext_apply');
        $this->assertSame(['hello-dolly'], $call['params']['slugs']);
        $this->assertSame('active', collect($s['alfa.nl']->refresh()->plugins)->firstWhere('name', 'hello-dolly')['status']);

        // Deleting an active plugin is refused per site; it needs the confirmation word.
        $this->post('/admin/wordpress/bulk', ['action' => 'plugins-delete', 'ids' => [$s['alfa.nl']->id],
            'options' => ['plugins-delete' => ['type' => 'plugin', 'op' => 'delete', 'slugs' => 'hello-dolly']]])->assertSessionHasErrors('confirm');
        $run = $this->bulk('plugins-delete', [$s['alfa.nl']->id], ['type' => 'plugin', 'op' => 'delete', 'slugs' => 'hello-dolly'], ['confirm' => 'confirm']);
        $this->assertSame('failed', $this->items($run)['alfa.nl'][0]);
        $this->assertStringContainsString('deactivate it first', $this->items($run)['alfa.nl'][1]);

        $this->bulk('plugins-manage', [$s['alfa.nl']->id], ['type' => 'plugin', 'op' => 'deactivate', 'slugs' => 'hello-dolly']);
        $run = $this->bulk('plugins-delete', [$s['alfa.nl']->id], ['type' => 'plugin', 'op' => 'delete', 'slugs' => 'hello-dolly'], ['confirm' => 'confirm']);
        $this->assertSame(['alfa.nl' => ['succeeded', 'Done: hello-dolly']], $this->items($run));

        // Theme rules.
        $run = $this->bulk('plugins-manage', [$s['alfa.nl']->id], ['type' => 'theme', 'op' => 'activate', 'slugs' => 'astra, twentytwentyfour']);
        $this->assertSame('skipped', $this->items($run)['alfa.nl'][0]);
    }

    public function test_install_a_plugin_and_skip_where_present(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->bulk('plugins-install', $this->ids($s), ['type' => 'plugin', 'slug' => 'wordpress-seo', 'activate' => '1']);
        $this->assertSame(['succeeded', 'succeeded'], array_column($this->items($run), 0));
        $this->assertSame(2, collect($this->agent()->calls)->where('action', 'wp.ext_install')->where('params.activate', true)->count());

        $run = $this->bulk('plugins-install', [$s['alfa.nl']->id], ['type' => 'plugin', 'slug' => 'woocommerce']);
        $this->assertSame(['alfa.nl' => ['skipped', 'Already installed.']], $this->items($run));
        $this->post('/admin/wordpress/bulk', ['action' => 'plugins-install', 'ids' => [$s['alfa.nl']->id],
            'options' => ['plugins-install' => ['type' => 'plugin', 'slug' => 'https://evil.example/x.zip']]])->assertSessionHasErrors('options.slug');
    }

    public function test_switches_and_cache(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->bulk('switch-maintenance', $this->ids($s), ['enabled' => '1', 'lang' => 'nl', 'title' => 'Even geduld']);
        $this->assertSame(['succeeded', 'succeeded'], array_column($this->items($run), 0));
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.maintenance_set');
        $this->assertTrue($call['params']['enabled']);
        $this->assertSame('nl', $call['params']['lang']);
        $this->assertSame('Even geduld', $call['params']['title']);
        $this->assertSame('On', $run->summary);

        $this->bulk('switch-debug', [$s['alfa.nl']->id], ['enabled' => '1', 'hours' => '4']);
        $this->assertTrue(\Rhis\WordPress\Switches\SwitchState::where('wordpress_install_id', $s['alfa.nl']->id)->value('debug_until') !== null);

        $run = $this->bulk('switch-indexing', [$s['alfa.nl']->id], ['enabled' => '0']);
        $this->assertSame('succeeded', $this->items($run)['alfa.nl'][0]);
        $this->assertTrue(collect($this->agent()->calls)->where('action', 'wp.indexing_set')->where('params.enabled', false)->isNotEmpty());

        $run = $this->bulk('clear-cache', $this->ids($s));
        $this->assertSame(['succeeded', 'Caches cleared.'], $this->items($run)['alfa.nl']);
        $this->assertContains('page_cache: ok', $run->items()->first()->details);
    }

    public function test_integrity_check_follows_the_job_and_stores_the_report(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->bulk('integrity-check', [$s['alfa.nl']->id]);
        $this->assertTrue(collect($this->agent()->calls)->where('action', 'wpint.check')->where('params.background', true)->isNotEmpty());
        $this->assertContains($this->items($run)['alfa.nl'][0], ['succeeded', 'attention']);
        $report = IntegrityReport::where('wordpress_install_id', $s['alfa.nl']->id)->first();
        $this->assertNotNull($report->checked_at);
        $this->assertNull($report->job_id);
    }
}
