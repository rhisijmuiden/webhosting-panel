<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\DbCleanup\DbCleanup;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WordPressDbCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.3']);
    }

    private function finished(array $result, string $status = 'succeeded', ?array $error = null): void
    {
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => $status, 'log' => ['Snapshot in /x', 'Cleaning'],
            'result' => $status === 'succeeded' ? $result : null, 'error' => $error]);
    }

    public function test_admin_sees_the_preview_and_the_card_on_the_install_page(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}")->assertOk()->assertSee('Database cleanup')
            ->assertSee(route('admin.wordpress.dbclean.show', $install), false);
        $this->get("/admin/wordpress/{$install->id}/database-cleanup")->assertOk()
            ->assertSee('Post revisions')->assertSee('1,284')->assertSee('Spam comments')->assertSee('4,812')
            ->assertSee('Home')->assertSee('wp_options')->assertSee('187.0 MB')
            ->assertSee('Optimize tables');
        $this->assertMatchesRegularExpression('/value="revisions" id="dbc-revisions"\s+checked/', $this->get("/admin/wordpress/{$install->id}/database-cleanup")->getContent());
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.dbclean_preview');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'keep_revisions' => 0], $call['params']);

        // Keeping revisions asks the agent again; all transients is never ticked by default.
        $page = $this->get("/admin/wordpress/{$install->id}/database-cleanup?keep=3")->assertOk()
            ->assertSee('The newest 3 revisions of every post are kept.')->getContent();
        $this->assertDoesNotMatchRegularExpression('/value="all_transients" id="dbc-all_transients"\s+checked/', $page);
        $this->assertSame(3, collect($this->agent()->calls)->where('action', 'wp.dbclean_preview')->last()['params']['keep_revisions']);
        $this->get("/admin/wordpress/{$install->id}/database-cleanup?keep=7")->assertOk();
        $this->assertSame(0, collect($this->agent()->calls)->where('action', 'wp.dbclean_preview')->last()['params']['keep_revisions']);
    }

    public function test_cleanup_starts_a_job_and_stores_the_outcome(): void
    {
        $install = $this->install();
        $admin = $this->actingAsAdmin();

        $res = $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => ['optimize', 'revisions', 'spam_comments'], 'keep_revisions' => 2]);
        $cleanup = DbCleanup::firstOrFail();
        $res->assertRedirect(route('admin.wordpress.dbclean.status', $cleanup));
        $call = collect($this->agent()->calls)->firstWhere('action', 'wp.dbclean_run');
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'items' => ['revisions', 'spam_comments', 'optimize'], 'keep_revisions' => 2], $call['params']);
        $this->assertSame('running', $cleanup->status);
        $this->assertSame($admin->id, $cleanup->user_id);
        $this->assertSame(1, ActivityLog::where('action', 'wordpress.db_cleanup')->count());

        // A second cleanup while one runs is refused.
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'running', 'log' => [], 'result' => null, 'error' => null]);
        $this->get(route('admin.wordpress.dbclean.status', $cleanup))->assertOk()->assertSee('http-equiv="refresh"', false);
        $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => ['revisions'], 'keep_revisions' => 0])
            ->assertSessionHasErrors('agent');
        $this->assertSame(1, DbCleanup::count());

        $this->finished(['removed' => ['revisions' => 900, 'spam_comments' => 4812, 'optimize' => 4], 'snapshot' => '20261107-101500',
            'db_size_before' => 190_000_000, 'db_size_after' => 120_000_000, 'rolled_back' => false]);
        $this->get(route('admin.wordpress.dbclean.status', $cleanup))->assertOk()
            ->assertSee('5,712')->assertSee('4 tables')->assertSee('20261107-101500')->assertSee('Snapshot in /x')
            ->assertDontSee('http-equiv="refresh"', false);
        $cleanup->refresh();
        $this->assertSame('succeeded', $cleanup->status);
        $this->assertSame(70_000_000, $cleanup->freed());
        $this->get("/admin/wordpress/{$install->id}")->assertSee('5712 items removed');
    }

    public function test_validation(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => [], 'keep_revisions' => 0])->assertSessionHasErrors('items');
        $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => ['users'], 'keep_revisions' => 0])->assertSessionHasErrors('items.0');
        $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => ['revisions'], 'keep_revisions' => 4])->assertSessionHasErrors('keep_revisions');
        $this->assertSame(0, DbCleanup::count());
    }

    public function test_a_rolled_back_cleanup_is_shown_and_collected_by_the_scheduler(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->post("/admin/wordpress/{$install->id}/database-cleanup", ['items' => ['all_transients'], 'keep_revisions' => 0]);
        $this->finished([], 'failed', ['code' => 'command_failed', 'message' => 'The site broke after the cleanup and the database was put back',
            'details' => ['rolled_back' => true, 'health' => ['before' => 200, 'after' => 200], 'snapshot' => '20261107-101500']]);

        $this->artisan('whp:wordpress:dbclean-collect')->assertSuccessful();
        $cleanup = DbCleanup::firstOrFail();
        $this->assertSame('rolled_back', $cleanup->status);
        $this->get(route('admin.wordpress.dbclean.status', $cleanup))->assertOk()->assertSee('the database was put back as it was');
    }

    public function test_agent_errors_are_shown(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.dbclean_preview', new AgentException('command_failed', 'Error establishing a database connection'));

        $this->get("/admin/wordpress/{$install->id}/database-cleanup")->assertOk()->assertSee('Error establishing a database connection')
            ->assertSee('Earlier cleanups');
    }

    public function test_customers_clean_only_their_own_sites_in_dutch(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mine.nl', $mine);
        $other = $this->install('other.nl');
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id, 'locale' => 'nl']));
        app()->setLocale('nl');

        $this->get("/wordpress/{$install->id}/database-cleanup")->assertOk()->assertSee('Revisies van berichten')->assertSee('Nu opschonen');
        $this->get("/websites/{$install->website_id}?tab=wordpress")->assertOk()->assertSee(route('client.wordpress.dbclean.show', $install), false);
        $this->post("/wordpress/{$install->id}/database-cleanup", ['items' => ['revisions'], 'keep_revisions' => 0])->assertRedirect();
        $cleanup = DbCleanup::firstOrFail();
        $this->assertSame($mine->id, $cleanup->customer_id);
        $this->get(route('client.wordpress.dbclean.status', $cleanup))->assertOk()->assertDontSee('Snapshot in /x');

        $this->get("/wordpress/{$other->id}/database-cleanup")->assertNotFound();
        $this->post("/wordpress/{$other->id}/database-cleanup", ['items' => ['revisions'], 'keep_revisions' => 0])->assertNotFound();
        $foreign = DbCleanup::create(['wordpress_install_id' => $other->id, 'status' => 'running', 'items' => ['revisions']]);
        $this->get(route('client.wordpress.dbclean.status', $foreign))->assertNotFound();

        config(['wordpress.customer_toolkit' => false]);
        $this->get("/wordpress/{$install->id}/database-cleanup")->assertNotFound();
    }
}
