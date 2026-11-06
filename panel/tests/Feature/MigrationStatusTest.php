<?php

namespace Tests\Feature;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Models\MigrationSource;
use Tests\TestCase;

class MigrationStatusTest extends TestCase
{
    use RefreshDatabase;

    private function startBakkerij(): MigrationItem
    {
        $source = MigrationSource::create([
            'name' => 'Plesk', 'type' => 'plesk', 'host' => 'plesk.example.test', 'port' => 22, 'user' => 'root',
            'status' => 'connected', 'inventory' => FakeAgentClient::pleskInventory(), 'inventoried_at' => now(),
        ]);
        $this->post(route('admin.migration.sources.migrate', $source), [
            'domains' => ['bakkerij.test'], 'customer' => ['bakkerij_test' => 'new'],
            'files' => 1, 'databases' => 1, 'mail' => 1, 'ssl' => 1,
        ])->assertRedirect();

        return MigrationItem::where('domain', 'bakkerij.test')->firstOrFail();
    }

    private function runningJob(MigrationItem $item, array $log): void
    {
        $this->agent()->respond('job.status', ['id' => $item->agent_job, 'status' => 'running', 'started_at' => time(), 'log' => $log, 'error' => null, 'result' => null]);
    }

    public function test_status_page_shows_progress_and_live_log(): void
    {
        $this->actingAsAdmin();
        $item = $this->startBakkerij();
        $this->assertSame('copying', $item->status);
        $this->runningJob($item, [
            'files: /var/www/vhosts/bakkerij.test/httpdocs -> /var/www/vhosts/bakkerij.test/public',
            "files: done {'files': 12}",
            'database wp_bakkerij -> wp_bakkerij',
        ]);

        $this->get(route('admin.migration.status'))->assertOk()
            ->assertSee('Migration status')
            ->assertSee('bakkerij.test')
            ->assertSee('Copying database wp_bakkerij')
            ->assertSee('Queued for migration from Plesk.')
            ->assertSee('Website created on this server.')
            ->assertSee('files: done', false);

        // 1 of 5 parts (files, database, certificate, 2 mailboxes) done: 15% + 60% / 5.
        $feed = $this->getJson(route('admin.migration.status.feed', ['item' => $item->id]))->assertOk()->json();
        $this->assertSame(1, $feed['counts']['running']);
        $this->assertSame($item->id, $feed['selected']);
        $this->assertSame(27, $feed['items'][0]['percent']);
        $this->assertSame(2, $feed['items'][0]['stage']);
        $this->assertSame('Copying database wp_bakkerij', $feed['items'][0]['step']);
        $this->assertContains('database wp_bakkerij -> wp_bakkerij', array_column($feed['log'], 'text'));
    }

    public function test_log_is_kept_after_the_copy_finished(): void
    {
        $this->actingAsAdmin();
        $item = $this->startBakkerij();
        $this->agent()->respond('job.status', ['id' => $item->agent_job, 'status' => 'succeeded', 'finished_at' => time(), 'error' => null,
            'log' => ["files: done {'files': 12}", 'database wp_bakkerij: failed: access denied'],
            'result' => ['domain' => 'bakkerij.test', 'files' => null, 'databases' => [], 'mail' => [], 'ssl' => null, 'errors' => ['database wp_bakkerij: access denied'], 'status' => 'partial']]);
        $this->get(route('admin.migration.items.show', $item))->assertOk();

        $item->refresh();
        $this->assertSame('copied', $item->status);
        $levels = array_column($item->logLines(), 'level', 'text');
        $this->assertSame('error', $levels['database wp_bakkerij: failed: access denied']);
        $this->assertSame('warn', $levels['Copy finished with 1 problems.']);
        $this->assertSame(75, $item->progress()['percent']);

        $this->get(route('admin.migration.items.show', $item))->assertOk()->assertSee('database wp_bakkerij: failed: access denied');
        auth()->user()->update(['locale' => 'nl']);
        $this->get(route('admin.migration.status'))->assertOk()->assertSee('Kopie klaar met 1 problemen.');
    }

    public function test_source_stays_and_lists_what_is_not_migrated_yet(): void
    {
        $this->actingAsAdmin();
        $item = $this->startBakkerij();
        $source = $item->source;

        $this->get(route('admin.migration.index'))->assertOk()
            ->assertSee('1 of 2 domains started, 0 switched')
            ->assertSee('Migrate next domains');

        $this->get(route('admin.migration.sources.show', ['source' => $source, 'show' => 'todo']))->assertOk()
            ->assertSee('api.bakkerij.test')
            ->assertDontSee('value="bakkerij.test"', false)
            ->assertSee('1 of 2 domains of this server are migrated or on their way.');

        $this->get(route('admin.migration.sources.show', ['source' => $source, 'show' => 'started']))->assertOk()
            ->assertSee('value="bakkerij.test"', false)
            ->assertDontSee('value="api.bakkerij.test"', false);
    }

    public function test_empty_status_page(): void
    {
        $this->actingAsAdmin();
        $this->get(route('admin.migration.status'))->assertOk()->assertSee('No migrations yet.');
    }
}
