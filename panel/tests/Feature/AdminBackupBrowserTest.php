<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Backups\Models\ClientBackup;
use Tests\TestCase;

class AdminBackupBrowserTest extends TestCase
{
    use RefreshDatabase;

    private function snapshots(): array
    {
        $s = fn ($n, $scope, $time, $kind = 'incremental') => ['id' => str_pad(dechex($n), 64, '0', STR_PAD_LEFT), 'short_id' => str_pad(dechex($n), 8, '0', STR_PAD_LEFT),
            'time' => $time, 'host' => 'web01', 'paths' => [], 'kind' => $kind, 'scope' => $scope, 'size' => null];

        return [
            $s(1, 'system', '2026-11-05T02:31:00Z', 'full'),
            $s(2, 'site:klant.nl', '2026-11-05T02:32:00Z', 'full'),
            $s(3, 'db:klant_wp', '2026-11-05T02:33:00Z', 'full'),
            $s(4, 'mail:klant.nl', '2026-11-05T02:34:00Z', 'full'),
            $s(5, 'site:ander.nl', '2026-11-05T02:35:00Z', 'full'),
            $s(6, 'site:weg.nl', '2026-11-05T02:36:00Z', 'full'),
            $s(7, 'site:klant.nl', '2026-11-06T02:32:00Z'),
            $s(8, 'site:klant.nl', '2026-11-06T09:00:00Z', 'pre-restore'),
        ];
    }

    public function test_days_and_groups_per_customer(): void
    {
        $this->actingAsAdmin();
        $jan = Customer::create(['name' => 'Jan', 'company' => 'Bakkerij Jan', 'email' => 'jan@example.test']);
        $piet = Customer::create(['name' => 'Piet', 'email' => 'piet@example.test']);
        $site = Website::create(['customer_id' => $jan->id, 'domain' => 'klant.nl']);
        Website::create(['customer_id' => $piet->id, 'domain' => 'ander.nl']);
        SiteDatabase::create(['website_id' => $site->id, 'name' => 'klant_wp']);
        $this->agent()->respond('backup.list', ['snapshots' => $this->snapshots()]);

        $page = $this->get('/admin/backups/browse?target=nas&date=2026-11-05')->assertOk()
            ->assertSee('Bakkerij Jan')->assertSee('Piet')->assertSee('Not linked to a customer')
            ->assertSee('Server configuration')->assertSee('Database klant_wp')->assertSee('date=2026-11-06', false);
        $html = $page->getContent();
        $this->assertStringContainsString(str_pad('3', 64, '0', STR_PAD_LEFT).'|db:klant_wp', $html);
        $this->assertStringContainsString('data-group="customer-'.$jan->id.'"', $html);
        // Pre-restore snapshots are not offered.
        $this->get('/admin/backups/browse?target=nas&date=2026-11-06')->assertOk()
            ->assertSee(str_pad('7', 64, '0', STR_PAD_LEFT), false)->assertDontSee(str_pad('8', 64, '0', STR_PAD_LEFT), false);
    }

    public function test_restore_selected_items_in_one_job(): void
    {
        $this->actingAsAdmin();
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $res = $this->post('/admin/backups/browse/restore', ['target' => 'nas', 'date' => '2026-11-05', 'mode' => 'in_place',
            'items' => ["{$a}|site:klant.nl", "{$b}|db:klant_wp", "{$a}|site:klant.nl"]]);
        $res->assertRedirect();
        $this->assertStringContainsString('/admin/backups/jobs/', $res->headers->get('Location'));
        $call = collect($this->agent()->calls)->firstWhere('action', 'backup.restore_many');
        $this->assertSame(['target' => 'nas', 'mode' => 'in_place', 'items' => [
            ['snapshot' => $a, 'scope' => 'site:klant.nl'], ['snapshot' => $b, 'scope' => 'db:klant_wp'],
        ]], $call['params']);

        $this->post('/admin/backups/browse/restore', ['target' => 'nas', 'date' => '2026-11-05', 'mode' => 'copy', 'items' => ["{$a}|system"]]);
        $this->assertSame('/root/restore/2026-11-05', collect($this->agent()->calls)->where('action', 'backup.restore_many')->last()['params']['to']);

        $this->post('/admin/backups/browse/restore', ['target' => 'nas', 'date' => '2026-11-05', 'mode' => 'in_place', 'items' => []])->assertSessionHasErrors('items');
        $this->post('/admin/backups/browse/restore', ['target' => 'nas', 'date' => '2026-11-05', 'mode' => 'in_place', 'items' => ["{$a}|site:../../etc"]])->assertSessionHasErrors('items.0');
        $this->post('/admin/backups/browse/restore', ['target' => 'nas', 'date' => 'gisteren', 'mode' => 'in_place', 'items' => ["{$a}|system"]])->assertSessionHasErrors('date');
    }

    public function test_customer_backups_overview(): void
    {
        $this->actingAsAdmin();
        $jan = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        ClientBackup::create(['customer_id' => $jan->id, 'domain' => 'klant.nl', 'action' => 'backup', 'kind' => 'full', 'status' => 'failed',
            'message' => 'Login incorrect', 'started_at' => now()]);
        $this->get('/admin/backups/customers')->assertOk()->assertSee('klant.nl')->assertSee('Login incorrect');
        $this->get('/admin/backups/customers?status=running')->assertOk()->assertDontSee('Login incorrect');
    }

    public function test_clients_cannot_open_the_browser(): void
    {
        $user = User::factory()->create(['customer_id' => Customer::create(['name' => 'J', 'email' => 'j@example.test'])->id]);
        $this->actingAs($user)->get('/admin/backups/browse')->assertForbidden();
        $this->actingAs($user)->post('/admin/backups/browse/restore', [])->assertForbidden();
    }
}
