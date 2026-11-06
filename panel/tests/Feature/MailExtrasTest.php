<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailAutoreply;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailImport;
use Tests\TestCase;

class MailExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private MailDomain $domain;

    private Mailbox $jan;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $this->domain = MailDomain::create(['customer_id' => $this->customer->id, 'domain' => 'klant.nl', 'dns_managed' => false]);
        $this->jan = $this->domain->mailboxes()->create(['local_part' => 'jan', 'display_name' => 'Jan Jansen', 'password_hash' => Mailbox::hashPassword('Geheim-123456'), 'quota_mb' => 0]);
        $this->user = User::factory()->create(['customer_id' => $this->customer->id]);
    }

    private function lastCall(string $action): array
    {
        return collect($this->agent()->calls)->where('action', $action)->last()['params'] ?? [];
    }

    public function test_autoreply_is_saved_and_sent_to_the_agent_with_its_addresses(): void
    {
        MailAlias::create(['mail_domain_id' => $this->domain->id, 'local_part' => 'info', 'targets' => ['jan@klant.nl']]);
        MailDomain::create(['customer_id' => $this->customer->id, 'domain' => 'klant.be', 'alias_of_id' => $this->domain->id, 'dns_managed' => false]);

        $this->actingAs($this->user)->put("/mail/domains/{$this->domain->id}/mailboxes/{$this->jan->id}/autoreply", [
            'enabled' => '1', 'subject' => 'Op vakantie', 'body' => "Ik ben weg.\nGroet, Jan", 'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(), 'interval_days' => 3,
        ])->assertRedirect("/mail/domains/{$this->domain->id}?tab=mailboxes")->assertSessionHasNoErrors();

        $reply = MailAutoreply::firstOrFail();
        $this->assertTrue($reply->enabled);
        $this->assertSame('scheduled', $reply->state());
        $params = $this->lastCall('mail.autoreply_sync')['autoreplies'][0];
        $this->assertSame('jan@klant.nl', $params['email']);
        $this->assertSame('Op vakantie', $params['subject']);
        $this->assertSame(3, $params['days']);
        $this->assertSame('Jan Jansen', $params['from_name']);
        $this->assertEqualsCanonicalizing(['info@klant.nl', 'jan@klant.be'], $params['addresses']);

        $this->actingAs($this->user)->get("/mail/domains/{$this->domain->id}?tab=mailboxes")->assertOk()
            ->assertSee(__('Autoreply (out of office)'))->assertSee('Op vakantie');

        // Switched off: the agent gets an empty list, so the script is removed.
        $this->actingAs($this->user)->put("/mail/domains/{$this->domain->id}/mailboxes/{$this->jan->id}/autoreply", ['enabled' => '0', 'interval_days' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->lastCall('mail.autoreply_sync')['autoreplies']);
        $this->assertSame('Op vakantie', $reply->fresh()->subject); // text is kept for next time
    }

    public function test_autoreply_validation_and_ownership(): void
    {
        $this->actingAs($this->user)->put("/mail/domains/{$this->domain->id}/mailboxes/{$this->jan->id}/autoreply", [
            'enabled' => '1', 'subject' => '', 'body' => 'x', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-01', 'interval_days' => 1,
        ])->assertSessionHasErrorsIn('autoreply-' . $this->jan->id, ['subject', 'ends_on']);
        $this->assertSame(0, MailAutoreply::count());

        $other = MailDomain::create(['customer_id' => Customer::create(['name' => 'Piet', 'email' => 'p@example.test'])->id, 'domain' => 'ander.nl', 'dns_managed' => false]);
        $box = $other->mailboxes()->create(['local_part' => 'piet', 'password_hash' => Mailbox::hashPassword('Geheim-123456'), 'quota_mb' => 0]);
        $this->actingAs($this->user)->put("/mail/domains/{$other->id}/mailboxes/{$box->id}/autoreply", ['enabled' => '1', 'subject' => 'a', 'body' => 'b', 'interval_days' => 1])->assertNotFound();
        $this->actingAs($this->user)->put("/mail/domains/{$this->domain->id}/mailboxes/{$box->id}/autoreply", ['enabled' => '1', 'subject' => 'a', 'body' => 'b', 'interval_days' => 1])->assertNotFound();
    }

    public function test_expired_autoreplies_and_other_providers_are_not_sent(): void
    {
        MailAutoreply::create(['mailbox_id' => $this->jan->id, 'enabled' => true, 'subject' => 'a', 'body' => 'b', 'ends_on' => now()->subDay()]);
        $this->assertSame('ended', MailAutoreply::first()->state());
        $this->assertSame(['autoreplies' => []], \Rhis\DnsMail\MailExtras\AutoreplyParams::build());
    }

    public function test_catch_all_modes(): void
    {
        $url = "/mail/domains/{$this->domain->id}/catch-all";
        $this->actingAs($this->user)->put($url, ['mode' => 'mailbox', 'mailbox_id' => $this->jan->id])->assertSessionHasNoErrors();
        $this->assertSame(['jan@klant.nl'], MailAlias::where('local_part', '')->firstOrFail()->targets);
        $aliases = collect($this->lastCall('mail.mailboxes_sync')['aliases']);
        $this->assertSame(['jan@klant.nl'], $aliases->firstWhere('address', '@klant.nl')['targets']);

        $this->actingAs($this->user)->put($url, ['mode' => 'forward', 'targets' => 'Jan@Gmail.com, kees@klant.nl'])->assertSessionHasErrors('targets');
        $this->actingAs($this->user)->put($url, ['mode' => 'forward', 'targets' => 'Jan@Gmail.com; jan@klant.nl'])->assertSessionHasNoErrors();
        $this->assertSame(['jan@gmail.com', 'jan@klant.nl'], MailAlias::where('local_part', '')->firstOrFail()->targets);
        $this->assertSame(1, MailAlias::count());

        $page = $this->actingAs($this->user)->get("/mail/domains/{$this->domain->id}?tab=mailboxes")->assertOk()->assertSee(__('Catch-all'));
        $page->assertDontSee('*@klant.nl'); // not listed again under Forwarding

        $this->actingAs($this->user)->put($url, ['mode' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame(0, MailAlias::count());
    }

    public function test_imap_import_test_start_progress_and_finish(): void
    {
        $this->actingAs($this->user)->postJson("/mail/domains/{$this->domain->id}/imports/test", [
            'host' => 'imap.oud.nl', 'port' => 993, 'security' => 'ssl', 'username' => 'jan@oud.nl', 'password' => 'pw',
        ])->assertOk()->assertJson(['ok' => true, 'messages' => 1284]);
        $this->assertSame('jan@oud.nl', $this->lastCall('mail.imap_import_test')['user']);

        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports", [
            'mailbox_id' => $this->jan->id, 'host' => 'IMAP.oud.nl.', 'port' => 993, 'security' => 'ssl', 'username' => 'jan@oud.nl', 'password' => 'oud-geheim',
        ])->assertRedirect("/mail/domains/{$this->domain->id}?tab=import")->assertSessionHasNoErrors();
        $import = MailImport::firstOrFail();
        $this->assertSame('running', $import->status);
        $this->assertSame('imap.oud.nl', $import->host);
        $this->assertSame('oud-geheim', $import->password);
        $this->assertNotSame('oud-geheim', $import->getRawOriginal('password')); // stored encrypted
        $start = $this->lastCall('mail.imap_import_start');
        $this->assertSame(['id' => $import->id, 'email' => 'jan@klant.nl', 'host' => 'imap.oud.nl', 'port' => 993, 'security' => 'ssl', 'user' => 'jan@oud.nl', 'password' => 'oud-geheim'], $start);

        // A second import into the same mailbox waits for the first.
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports", [
            'mailbox_id' => $this->jan->id, 'host' => 'imap.oud.nl', 'port' => 993, 'security' => 'ssl', 'username' => 'jan@oud.nl', 'password' => 'x',
        ])->assertSessionHasErrorsIn('import', 'mailbox_id');

        $this->actingAs($this->user)->getJson("/mail/domains/{$this->domain->id}/imports/{$import->id}")->assertOk()
            ->assertJson(['status' => 'running', 'percent' => 61, 'done' => 787, 'total' => 1284]);
        $this->actingAs($this->user)->get("/mail/domains/{$this->domain->id}?tab=import")->assertOk()
            ->assertSee(__('Import a mailbox'))->assertSee('jan@oud.nl')->assertSee('61%');

        $this->agent()->respond('mail.imap_import_status', ['id' => $import->id, 'state' => 'partial', 'exit_code' => 111, 'error_lines' => ['ERR: quota exceeded'], 'log' => ['Detected 2 errors'],
            'progress' => ['total' => 1284, 'left' => null, 'done' => 1284, 'percent' => 100, 'transferred' => 1280, 'skipped' => 2, 'errors' => 2, 'bytes' => 1000, 'finished_stats' => true]]);
        Artisan::call('dns-mail:imports');
        $import->refresh();
        $this->assertSame('partial', $import->status);
        $this->assertSame(1280, $import->transferred);
        $this->assertNull($import->password); // gone once the run ended
        $this->assertSame('ERR: quota exceeded', $import->error);

        // Run again needs the password again.
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports/{$import->id}/retry", [])->assertSessionHasErrorsIn('retry-' . $import->id, 'password');
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports/{$import->id}/retry", ['password' => 'nieuw'])->assertSessionHasNoErrors();
        $this->assertSame('running', $import->fresh()->status);
        $this->assertSame(2, $import->fresh()->runs);

        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports/{$import->id}/stop")->assertRedirect();
        $this->assertSame('stopped', $import->fresh()->status);
        $this->actingAs($this->user)->delete("/mail/domains/{$this->domain->id}/imports/{$import->id}")->assertRedirect();
        $this->assertSame(0, MailImport::count());
        $this->assertContains('mail.imap_import_forget', array_column($this->agent()->calls, 'action'));
    }

    public function test_busy_server_queues_and_tick_starts_later(): void
    {
        $this->agent()->respond('mail.imap_import_start', new AgentException('conflict', '3 imports are running already', ['busy' => true]));
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports", [
            'mailbox_id' => $this->jan->id, 'host' => 'imap.oud.nl', 'port' => 993, 'security' => 'ssl', 'username' => 'jan@oud.nl', 'password' => 'pw',
        ])->assertSessionHasNoErrors();
        $this->assertSame('queued', MailImport::firstOrFail()->status);

        $this->agent()->respond('mail.imap_import_start', ['state' => 'running']);
        Artisan::call('dns-mail:imports');
        $this->assertSame('running', MailImport::firstOrFail()->status);
    }

    public function test_failed_start_shows_the_reason_and_drops_the_password(): void
    {
        $this->agent()->respond('mail.imap_import_start', new AgentException('forbidden', 'imap.lan points to a private or local address'));
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports", [
            'mailbox_id' => $this->jan->id, 'host' => 'imap.lan', 'port' => 993, 'security' => 'ssl', 'username' => 'a', 'password' => 'pw',
        ])->assertSessionHas('warnings');
        $import = MailImport::firstOrFail();
        $this->assertSame('failed', $import->status);
        $this->assertNull($import->password);
    }

    public function test_customers_cannot_reach_other_imports_and_admin_sees_all(): void
    {
        $other = Customer::create(['name' => 'Piet', 'email' => 'p@example.test']);
        $domain = MailDomain::create(['customer_id' => $other->id, 'domain' => 'ander.nl', 'dns_managed' => false]);
        $box = $domain->mailboxes()->create(['local_part' => 'piet', 'password_hash' => Mailbox::hashPassword('Geheim-123456'), 'quota_mb' => 0]);
        $import = MailImport::create(['mailbox_id' => $box->id, 'host' => 'imap.x.nl', 'port' => 993, 'security' => 'ssl', 'username' => 'piet', 'status' => 'done', 'percent' => 100]);

        $this->actingAs($this->user)->getJson("/mail/domains/{$this->domain->id}/imports/{$import->id}")->assertNotFound();
        $this->actingAs($this->user)->getJson("/mail/domains/{$domain->id}/imports/{$import->id}")->assertNotFound();
        $this->actingAs($this->user)->post("/mail/domains/{$this->domain->id}/imports", [
            'mailbox_id' => $box->id, 'host' => 'imap.x.nl', 'port' => 993, 'security' => 'ssl', 'username' => 'a', 'password' => 'pw',
        ])->assertNotFound();

        $this->actingAsAdmin();
        $this->get('/admin/mail-imports')->assertOk()->assertSee('piet@ander.nl')->assertSee('Piet');
        $this->get('/admin/mail-imports?status=problems')->assertOk()->assertDontSee('piet@ander.nl');
        $this->get("/admin/domains/{$domain->id}?tab=import")->assertOk()->assertSee('imap.x.nl');
    }

    public function test_microsoft_365_domains_have_no_import_tab(): void
    {
        $this->domain->update(['provider' => 'microsoft365']);
        $this->actingAs($this->user)->get("/mail/domains/{$this->domain->id}?tab=mailboxes")->assertOk()->assertDontSee(__('Import mail'));
        $this->actingAs($this->user)->put("/mail/domains/{$this->domain->id}/catch-all", ['mode' => 'off'])->assertNotFound();
    }
}
