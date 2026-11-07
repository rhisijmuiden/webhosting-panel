<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Bulk\BulkActions;
use Rhis\WordPress\Bulk\CallbackAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Models\BulkRunItem;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WordPressBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wordpress.bulk.poll_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        BulkActions::forget('test-hello');
        BulkActions::forget('test-wipe');
        parent::tearDown();
    }

    /** Three WordPress sites; the fake agent reports updates for all of them, so mark one as up to date. */
    private function sites(): array
    {
        $a = Customer::create(['name' => 'Alfa', 'email' => 'info@alfa.nl']);
        $b = Customer::create(['name' => 'Bravo', 'email' => 'info@bravo.nl']);
        foreach ([['alfa.nl', $a], ['bravo.nl', $b], ['charlie.nl', $b]] as [$domain, $c]) {
            Website::create(['customer_id' => $c->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);
        }
        $this->artisan('whp:wordpress:scan')->assertSuccessful();
        $installs = WordPressInstall::with('website')->get()->keyBy(fn ($i) => $i->website->domain);
        $installs['charlie.nl']->update(['core_update' => null, 'outdated_plugins' => 0, 'outdated_themes' => 0,
            'plugins' => [['name' => 'wordfence', 'title' => 'Wordfence', 'status' => 'active', 'version' => '8.0.1', 'update' => false]]]);

        return $installs->all();
    }

    private function runFrom($response): BulkRun
    {
        $response->assertRedirect();
        $this->assertMatchesRegularExpression('#/admin/wordpress/bulk/runs/\d+$#', $response->headers->get('Location'));

        return BulkRun::findOrFail((int) basename($response->headers->get('Location')));
    }

    private function items(BulkRun $run): array
    {
        return $run->items()->get()->mapWithKeys(fn (BulkRunItem $i) => [$i->label => $i->status])->all();
    }

    public function test_page_lists_actions_sites_and_filters(): void
    {
        $this->sites();
        $this->actingAsAdmin();

        $this->get('/admin/wordpress')->assertOk()->assertSee(route('admin.wordpress.bulk.index'), false);
        $this->get('/admin/wordpress/bulk')->assertOk()
            ->assertSee('Bulk actions')->assertSee('Update WordPress')->assertSee('Update specific plugins or themes')
            ->assertSee('Check for updates')->assertSee('Set WP-cron')->assertSee('Security scan')->assertSee('Empty the object cache')
            ->assertSee('alfa.nl')->assertSee('charlie.nl')->assertSee('WooCommerce')->assertSee('assets/wp-bulk.js', false);
        $this->get('/admin/wordpress/bulk?action=update-plugins&slugs=woocommerce&filter=outdated')->assertOk()
            ->assertSee('value="woocommerce"', false);
    }

    public function test_only_admins(): void
    {
        $this->sites();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/wordpress/bulk')->assertForbidden();
        $this->post('/admin/wordpress/bulk', ['action' => 'check', 'ids' => [1]])->assertForbidden();
    }

    public function test_update_runs_per_site_skips_up_to_date_and_records_the_outcome(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();
        $this->agent()->respond('job.status', fn ($p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'updated' => ['core' => ['old_version' => '6.6.2', 'new_version' => '6.7.1'], 'plugins' => [['name' => 'woocommerce', 'old_version' => '9.1.2', 'new_version' => '9.4.1']], 'themes' => []],
            'errors' => [], 'rolled_back' => false, 'health' => ['before' => 200, 'after' => 200],
        ]]);

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', [
            'action' => 'update', 'ids' => array_map(fn ($i) => $i->id, array_values($s)), 'concurrency' => 2,
            'options' => ['update' => ['core' => '1', 'plugins' => '1', 'themes' => '1', 'snapshot' => '1', 'only_outdated' => '1']],
        ]));

        $this->assertSame('finished', $run->refresh()->status);
        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'succeeded', 'charlie.nl' => 'skipped'], $this->items($run));
        $calls = collect($this->agent()->calls)->where('action', 'wp.update')->values();
        $this->assertCount(2, $calls);
        $this->assertSame(['alfa.nl', 'bravo.nl'], $calls->pluck('params.domain')->all());
        $this->assertTrue($calls[0]['params']['snapshot']);
        $this->assertSame('all', $calls[0]['params']['plugins']);

        $alfa = $run->items()->where('label', 'alfa.nl')->first();
        $this->assertSame('2 items updated.', $alfa->message);
        $this->assertContains('Core: 6.6.2 → 6.7.1', $alfa->details);
        $this->assertContains('Plugins: woocommerce 9.4.1', $alfa->details);
        $this->assertSame('succeeded', $s['alfa.nl']->refresh()->last_update_status);
        $this->assertSame('Already up to date.', $run->items()->where('label', 'charlie.nl')->value('message'));
        $this->assertTrue(ActivityLog::where('action', 'wordpress.bulk')->exists());

        $this->get("/admin/wordpress/bulk/runs/{$run->id}")->assertOk()->assertSee('Update WordPress')->assertSee('alfa.nl');
        $this->getJson("/admin/wordpress/bulk/runs/{$run->id}/status")->assertOk()
            ->assertJsonPath('run.status', 'finished')->assertJsonPath('run.counts.succeeded', 2)->assertJsonPath('run.counts.skipped', 1)
            ->assertJsonPath('run.counts.percent', 100)->assertJsonCount(3, 'items');
        $this->get('/admin/wordpress/bulk')->assertSee('Recent bulk runs')->assertSee(route('admin.wordpress.bulk.show', $run), false);
    }

    public function test_rolled_back_update_is_reported_as_failed(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();
        $this->agent()->respond('job.status', fn ($p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => [], 'error' => null, 'result' => [
            'updated' => ['plugins' => [['name' => 'woocommerce']]], 'errors' => [], 'rolled_back' => true,
        ]]);
        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'update', 'ids' => [$s['alfa.nl']->id],
            'options' => ['update' => ['core' => '1', 'plugins' => '1', 'themes' => '1', 'snapshot' => '1', 'only_outdated' => '1']]]));

        $item = $run->items()->first();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('rolled back', $item->message);
    }

    public function test_update_specific_plugins_only_where_installed_and_outdated(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', [
            'action' => 'update-plugins', 'ids' => array_map(fn ($i) => $i->id, array_values($s)),
            'options' => ['update-plugins' => ['type' => 'plugin', 'slugs' => 'woocommerce, wordfence', 'snapshot' => '1']],
        ]));

        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'succeeded', 'charlie.nl' => 'skipped'], $this->items($run));
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.update');
        $this->assertFalse($call['params']['core']);
        $this->assertSame(['woocommerce'], $call['params']['plugins']);
        $this->assertFalse($call['params']['themes']);
        $this->assertSame('Plugins: woocommerce, wordfence', $run->summary);

        // Slugs are validated.
        $this->post('/admin/wordpress/bulk', ['action' => 'update-plugins', 'ids' => [$s['alfa.nl']->id],
            'options' => ['update-plugins' => ['type' => 'plugin', 'slugs' => '../etc; rm']]])->assertSessionHasErrors('options.slugs');
    }

    public function test_pauses_after_failures_then_resume_and_retry(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.update', fn ($p) => $p['domain'] === 'alfa.nl'
            ? throw new AgentException('command_failed', 'WP-CLI is broken on alfa.nl')
            : ['job' => (string) \Illuminate\Support\Str::uuid()]);

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', [
            'action' => 'update', 'ids' => [$s['alfa.nl']->id, $s['bravo.nl']->id], 'concurrency' => 1, 'stop_after_failures' => 1,
            'options' => ['update' => ['core' => '1', 'plugins' => '1', 'themes' => '1', 'snapshot' => '1', 'only_outdated' => '1']],
        ]));

        $this->assertSame('paused', $run->refresh()->status);
        $this->assertSame(['alfa.nl' => 'failed', 'bravo.nl' => 'pending'], $this->items($run));
        $this->assertSame('WP-CLI is broken on alfa.nl', $run->items()->first()->message);
        $this->get("/admin/wordpress/bulk/runs/{$run->id}")->assertSee('Paused after 1 failed site')->assertSee('Resume');

        $this->post("/admin/wordpress/bulk/runs/{$run->id}/resume")->assertRedirect();
        $this->assertSame('finished', $run->refresh()->status);
        $this->assertSame(['alfa.nl' => 'failed', 'bravo.nl' => 'succeeded'], $this->items($run));

        $this->agent()->respond('wp.update', fn () => ['job' => (string) \Illuminate\Support\Str::uuid()]);
        $retry = $this->runFrom($this->post("/admin/wordpress/bulk/runs/{$run->id}/retry"));
        $this->assertNotSame($run->id, $retry->id);
        $this->assertSame(['alfa.nl' => 'succeeded'], $this->items($retry));
    }

    public function test_cancel_stops_sites_that_did_not_start(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();
        $this->agent()->respond('job.status', fn ($p) => ['id' => $p['job'], 'status' => 'running', 'log' => [], 'result' => null, 'error' => null]);
        config(['wordpress.bulk.slice_seconds' => 0]); // one round per worker turn

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', [
            'action' => 'update', 'ids' => [$s['alfa.nl']->id, $s['bravo.nl']->id], 'concurrency' => 1,
            'options' => ['update' => ['core' => '1', 'plugins' => '1', 'themes' => '1', 'snapshot' => '1', 'only_outdated' => '1']],
        ]));
        $this->assertSame(['alfa.nl' => 'running', 'bravo.nl' => 'pending'], $this->items($run));

        $this->post("/admin/wordpress/bulk/runs/{$run->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $run->refresh()->status);
        $this->assertNull($run->finished_at, 'the busy site still finishes');
        $this->assertSame(['alfa.nl' => 'running', 'bravo.nl' => 'cancelled'], $this->items($run));

        // The busy site ends; the scheduler picks the run up and closes it.
        $this->agent()->respond('job.status', fn ($p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => [], 'result' => [], 'error' => null]);
        $this->travel(5)->minutes();
        $this->artisan('whp:wp-bulk:tick')->assertSuccessful();
        $this->assertNotNull($run->refresh()->finished_at);
        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'cancelled'], $this->items($run));
    }

    public function test_check_and_wp_cron_actions(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'check', 'ids' => [$s['alfa.nl']->id]]));
        $this->assertSame(['alfa.nl' => 'succeeded'], $this->items($run));
        $this->assertSame('4 updates available.', $run->items()->first()->message);

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'wp-cron', 'ids' => [$s['alfa.nl']->id, $s['bravo.nl']->id],
            'options' => ['wp-cron' => ['mode' => 'system', 'interval' => '15']]]));
        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'succeeded'], $this->items($run));
        $this->assertSame(15, $s['alfa.nl']->refresh()->cron_interval);
        $this->assertSame('system', $s['alfa.nl']->cron_mode);
        $this->assertSame(2, collect($this->agent()->calls)->where('action', 'wp.cron_set')->where('params.interval', 15)->count());

        // Already set like this: skipped.
        $again = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'wp-cron', 'ids' => [$s['alfa.nl']->id],
            'options' => ['wp-cron' => ['mode' => 'system', 'interval' => '15']]]));
        $this->assertSame(['alfa.nl' => 'skipped'], $this->items($again));

        $this->post('/admin/wordpress/bulk', ['action' => 'wp-cron', 'ids' => [$s['alfa.nl']->id],
            'options' => ['wp-cron' => ['mode' => 'sometimes']]])->assertSessionHasErrors('options.mode');
        $this->post('/admin/wordpress/bulk', ['action' => 'nope', 'ids' => [$s['alfa.nl']->id]])->assertSessionHasErrors('action');
        $this->post('/admin/wordpress/bulk', ['action' => 'check', 'ids' => []])->assertSessionHasErrors('ids');
    }

    public function test_security_scan_runs_once_per_website(): void
    {
        $s = $this->sites();
        WordPressInstall::create(['website_id' => $s['alfa.nl']->website_id, 'path' => 'blog', 'version' => '6.6.2']);
        $this->actingAsAdmin();

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'security-scan',
            'ids' => WordPressInstall::where('website_id', $s['alfa.nl']->website_id)->pluck('id')->all()]));
        $this->assertSame(1, $run->total);
        $this->assertSame(['alfa.nl'], array_keys($this->items($run)));
        $this->assertSame(1, collect($this->agent()->calls)->where('action', 'malware.scan')->count());
        $this->assertContains($run->items()->first()->status, ['succeeded', 'attention']);
    }

    public function test_packages_can_register_their_own_actions(): void
    {
        $s = $this->sites();
        $seen = [];
        BulkActions::register(new CallbackAction(
            key: 'test-hello', label: fn () => 'Say hello', group: 'maintenance', description: 'A test action',
            fields: [Field::text('word', 'Word', default: 'hello'), Field::number('times', 'Times', 2, 1, 5)],
            rules: ['word' => ['required', 'alpha'], 'times' => ['integer', 'between:1,5']],
            skip: fn (WordPressInstall $i) => $i->website->domain === 'bravo.nl' ? 'Not here' : null,
            run: function (WordPressInstall $i, array $o) use (&$seen) {
                $seen[] = [$i->label(), $o['word'], $o['times']];

                return $i->website->domain === 'charlie.nl' ? ['job' => 'abc'] : Step::ok(str_repeat($o['word'], $o['times']));
            },
            describe: fn ($o) => "word {$o['word']}",
        ));
        BulkActions::register(new CallbackAction(key: 'test-wipe', label: 'Wipe', group: 'danger-zone', dangerous: true, run: fn () => Step::ok()));
        $this->actingAsAdmin();

        $this->get('/admin/wordpress/bulk')->assertSee('Say hello')->assertSee('A test action')->assertSee('Danger zone')->assertSee('options[test-hello][word]', false);
        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'test-hello', 'ids' => array_map(fn ($i) => $i->id, array_values($s)),
            'options' => ['test-hello' => ['word' => 'hoi', 'times' => '3']]]));

        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'skipped', 'charlie.nl' => 'succeeded'], $this->items($run));
        $this->assertSame([['alfa.nl', 'hoi', 3], ['charlie.nl', 'hoi', 3]], $seen);
        $this->assertSame('hoihoihoi', $run->items()->where('label', 'alfa.nl')->value('message'));
        $this->assertSame('word hoi', $run->summary);

        // A dangerous action needs the confirmation word.
        $this->post('/admin/wordpress/bulk', ['action' => 'test-wipe', 'ids' => [$s['alfa.nl']->id]])->assertSessionHasErrors('confirm');
        $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'test-wipe', 'ids' => [$s['alfa.nl']->id], 'confirm' => 'confirm']));
    }

    public function test_an_exception_in_an_action_fails_only_that_site_and_dutch_texts(): void
    {
        $s = $this->sites();
        BulkActions::register(new CallbackAction(key: 'test-hello', label: 'Boom', group: 'maintenance',
            run: fn (WordPressInstall $i) => $i->website->domain === 'alfa.nl' ? throw new \RuntimeException('kapot') : Step::ok()));
        $this->actingAsAdmin();

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'test-hello', 'ids' => [$s['alfa.nl']->id, $s['bravo.nl']->id]]));
        $this->assertSame(['alfa.nl' => 'failed', 'bravo.nl' => 'succeeded'], $this->items($run));
        $this->assertStringContainsString('kapot', $run->items()->first()->message);

        $this->get('/admin/wordpress/bulk')->assertOk();
        app()->setLocale('nl');
        $this->assertSame('Bulkacties', __('Bulk actions'));
        $this->assertSame('WordPress bijwerken', __('Update WordPress'));

        // Results are written in the language of the admin who started the run.
        app()->setLocale('en');
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'update-plugins', 'ids' => [$s['charlie.nl']->id],
            'options' => ['update-plugins' => ['type' => 'plugin', 'slugs' => 'akismet']]]));
        $this->assertSame('Niet geïnstalleerd op deze site.', $run->items()->first()->message);
    }

    public function test_on_a_real_queue_each_round_is_a_short_job(): void
    {
        $s = $this->sites();
        $this->actingAsAdmin();
        config(['queue.default' => 'redis']);
        \Illuminate\Support\Facades\Queue::fake();

        $run = $this->runFrom($this->post('/admin/wordpress/bulk', ['action' => 'update', 'ids' => [$s['alfa.nl']->id, $s['bravo.nl']->id], 'concurrency' => 1,
            'options' => ['update' => ['core' => '1', 'plugins' => '1', 'themes' => '1', 'snapshot' => '1', 'only_outdated' => '1']]]));
        \Illuminate\Support\Facades\Queue::assertPushed(\Rhis\WordPress\Bulk\Jobs\AdvanceBulkRun::class, 1);
        $this->assertSame(['alfa.nl' => 'pending', 'bravo.nl' => 'pending'], $this->items($run));

        // Each round does a little and queues the next one instead of keeping the worker busy.
        $round = function () use ($run) {
            $job = new \Rhis\WordPress\Bulk\Jobs\AdvanceBulkRun($run->id);
            (new \Illuminate\Bus\UniqueLock(app('cache')->driver()))->release($job); // what the worker does when it picks the job up
            $job->handle(app(\Rhis\WordPress\Bulk\Runner::class));
        };
        $round();
        $this->assertSame(['alfa.nl' => 'running', 'bravo.nl' => 'pending'], $this->items($run));
        \Illuminate\Support\Facades\Queue::assertPushed(\Rhis\WordPress\Bulk\Jobs\AdvanceBulkRun::class, 2);
        $round();
        $round();
        $round();
        $this->assertSame(['alfa.nl' => 'succeeded', 'bravo.nl' => 'succeeded'], $this->items($run));
        $this->assertSame('finished', $run->refresh()->status);
    }
}
