<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailLogMessage;
use Rhis\DnsMail\Services\MailLogCollector;
use Tests\TestCase;

class MailLogTest extends TestCase
{
    use RefreshDatabase;

    private Customer $jan;

    private Customer $piet;

    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->jan = Customer::create(['name' => 'Jan', 'company' => 'Klant BV', 'email' => 'jan@example.test']);
        $this->piet = Customer::create(['name' => 'Piet', 'email' => 'piet@example.test']);
        $klant = MailDomain::create(['customer_id' => $this->jan->id, 'domain' => 'klant.nl', 'dns_managed' => false]);
        Mailbox::create(['mail_domain_id' => $klant->id, 'local_part' => 'info', 'password_hash' => 'x', 'quota_mb' => 1024, 'enabled' => true]);
        $site = Website::createQuietly(['customer_id' => $this->piet->id, 'domain' => 'shop.nl', 'status' => 'active', 'system_user' => 's_shop_nl']);
        MailDomain::create(['customer_id' => $this->piet->id, 'website_id' => $site->id, 'domain' => 'shop.nl', 'dns_managed' => false]);

        $this->agent()->respond('mail.log_read', function (array $params) {
            $page = $this->pages[$params['cursor'] ?? 'start'] ?? ['events' => [], 'cursor' => $params['cursor'] ?? null];

            return $page + ['more' => false];
        });
        $this->agent()->respond('mail.spam_history', ['rows' => [
            ['qid' => 'AAAAAAAAAAAA01', 'score' => 1.2, 'action' => 'no action', 'subject' => 'Offerte', 'symbols' => [['name' => 'DKIM_SIGNED', 'score' => 0]]],
            ['qid' => 'CCCCCCCCCCCC03', 'score' => 13.5, 'action' => 'add header', 'subject' => 'Cheap pills', 'symbols' => [['name' => 'BAYES_SPAM', 'score' => 5.1]]],
        ]]);
    }

    private function ev(string $kind, ?string $qid, array $data = [], int $t = 0): array
    {
        return ['kind' => $kind, 'qid' => $qid, 'time' => now()->subMinutes(5)->timestamp + $t] + $data;
    }

    private function feed(): void
    {
        $a = 'AAAAAAAAAAAA01'; // Jan via SMTP login, to Gmail
        $b = 'BBBBBBBBBBBB02'; // Piet's website (PHP mail), one bounce
        $c = 'CCCCCCCCCCCC03'; // spam from outside to Jan
        $this->pages['start'] = ['cursor' => 'c1', 'more' => true, 'events' => [
            $this->ev('client', $a, ['service' => 'submission', 'client_ip' => '203.0.113.5', 'client_host' => 'unknown', 'sasl_username' => 'Info@klant.nl']),
            $this->ev('message_id', $a, ['message_id' => 'm1@klant.nl'], 1),
            $this->ev('queued', $a, ['from' => 'info@klant.nl', 'size' => 2048, 'nrcpt' => 1], 1),
            $this->ev('delivery', $a, ['to' => 'x@gmail.com', 'status' => 'deferred', 'local' => false, 'relay' => 'gmail-smtp-in.l.google.com[142.250.1.1]:25', 'dsn' => '4.7.0', 'response' => 'try later'], 2),
            $this->ev('pickup', $b, ['uid' => 1005, 'user' => 's_shop_nl', 'from' => 's_shop_nl'], 3),
            $this->ev('queued', $b, ['from' => 'noreply@shop.nl', 'size' => 900, 'nrcpt' => 2], 3),
        ]];
        $this->pages['c1'] = ['cursor' => 'c2', 'events' => [
            $this->ev('delivery', $a, ['to' => 'x@gmail.com', 'status' => 'sent', 'local' => false, 'dsn' => '2.0.0', 'response' => '250 ok'], 60),
            $this->ev('removed', $a, [], 60),
            $this->ev('delivery', $b, ['to' => 'klant1@example.org', 'status' => 'sent', 'local' => false], 61),
            $this->ev('delivery', $b, ['to' => 'weg@nope.example', 'status' => 'bounced', 'local' => false, 'dsn' => '5.1.1'], 61),
            $this->ev('client', $c, ['service' => 'smtp', 'client_ip' => '198.51.100.66', 'client_host' => 'bad.example']),
            $this->ev('queued', $c, ['from' => 'spam@bad.example', 'nrcpt' => 1], 62),
            $this->ev('delivery', $c, ['to' => 'info@klant.nl', 'status' => 'sent', 'local' => true, 'relay' => 'klant.nl[private/dovecot-lmtp]'], 62),
            $this->ev('reject', null, ['service' => 'smtp', 'client_ip' => '198.51.100.7', 'from' => 'a@b.example', 'to' => 'x@elders.example', 'code' => '554', 'dsn' => '5.7.1', 'reason' => 'Relay access denied'], 63),
        ]];
        app(MailLogCollector::class)->collect();
    }

    public function test_collector_merges_events_and_links_customers(): void
    {
        $this->feed();

        $this->assertSame('c2', app(Settings::class)->get(MailLogCollector::CURSOR_KEY));
        $this->assertSame(4, MailLogMessage::count());

        $a = MailLogMessage::where('queue_id', 'AAAAAAAAAAAA01')->firstOrFail();
        $this->assertSame(['authenticated', 'outgoing', 'sent'], [$a->origin, $a->direction, $a->status]);
        $this->assertSame($this->jan->id, $a->customer_id);
        $this->assertSame('info@klant.nl', $a->mailbox->address());
        $this->assertSame(2, $a->recipients->first()->attempts);
        $this->assertNotNull($a->finished_at);
        $this->assertSame(1.2, $a->spam_score);
        $this->assertSame('Offerte', $a->subject);

        $b = MailLogMessage::where('queue_id', 'BBBBBBBBBBBB02')->firstOrFail();
        $this->assertSame(['website', 'outgoing', 'mixed'], [$b->origin, $b->direction, $b->status]);
        $this->assertSame($this->piet->id, $b->customer_id);
        $this->assertNotNull($b->website_id);
        $this->assertSame('shop.nl', $b->mailDomain->domain);

        $c = MailLogMessage::where('queue_id', 'CCCCCCCCCCCC03')->firstOrFail();
        $this->assertSame(['inbound', 'incoming', 'sent'], [$c->origin, $c->direction, $c->status]);
        $this->assertSame($this->jan->id, $c->customer_id); // the receiving customer
        $this->assertSame(13.5, $c->spam_score);

        $r = MailLogMessage::whereNull('queue_id')->firstOrFail();
        $this->assertSame(['rejected', 'inbound'], [$r->status, $r->origin]);
        $this->assertStringContainsString('Relay access denied', $r->reject_reason);
        $this->assertNull($r->customer_id);

        // A second run continues at the cursor and adds nothing.
        app(MailLogCollector::class)->collect();
        $this->assertSame(4, MailLogMessage::count());
        $this->assertSame('c2', collect($this->agent()->calls)->where('action', 'mail.log_read')->last()['params']['cursor']);
    }

    public function test_collector_survives_missing_rspamd(): void
    {
        $this->agent()->respond('mail.spam_history', new AgentException('command_failed', 'Rspamd down'));
        $this->feed();
        $this->assertSame(4, MailLogMessage::count());
        $this->assertNull(MailLogMessage::where('queue_id', 'CCCCCCCCCCCC03')->value('spam_score'));
    }

    public function test_admin_page_filters_per_customer_and_flags_spammers(): void
    {
        $this->feed();
        app(Settings::class)->set('dnsmail.maillog_alert_per_hour', 1);
        $this->actingAsAdmin();

        $this->get('/admin/mail-log')->assertOk()
            ->assertSee('Mail log')->assertSee('x@gmail.com')->assertSee('spam@bad.example')
            ->assertSee('Outgoing mail per customer')->assertSee('Klant BV')->assertSee('High volume');

        $this->get('/admin/mail-log?customer=' . $this->piet->id)->assertOk()
            ->assertSee('noreply@shop.nl')->assertDontSee('spam@bad.example');
        $this->get('/admin/mail-log?mailbox=info@klant.nl&direction=outgoing')->assertOk()
            ->assertSee('x@gmail.com')->assertDontSee('noreply@shop.nl');
        $this->get('/admin/mail-log?domain=shop.nl')->assertOk()->assertSee('noreply@shop.nl')->assertDontSee('x@gmail.com');
        $this->get('/admin/mail-log?status=failed')->assertOk()->assertSee('Relay access denied')->assertDontSee('x@gmail.com');
        $this->get('/admin/mail-log?spam=1')->assertOk()->assertSee('Cheap pills')->assertDontSee('Offerte');
        $this->get('/admin/mail-log?q=m1@klant')->assertOk()->assertSee('x@gmail.com')->assertDontSee('noreply@shop.nl');
        $this->get('/admin/mail-log?direction=bogus')->assertSessionHasErrors('direction');
    }

    public function test_detail_export_and_settings(): void
    {
        $this->feed();
        $this->actingAsAdmin();
        $b = MailLogMessage::where('queue_id', 'BBBBBBBBBBBB02')->firstOrFail();

        $this->get("/admin/mail-log/{$b->id}")->assertOk()
            ->assertSee('weg@nope.example')->assertSee('5.1.1')->assertSee('s_shop_nl')->assertSee('shop.nl');

        $csv = $this->get('/admin/mail-log/export?customer=' . $this->jan->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('AAAAAAAAAAAA01', $csv);
        $this->assertStringNotContainsString('BBBBBBBBBBBB02', $csv);

        $this->put('/admin/mail-log/settings', ['retention_days' => 7, 'alert_per_hour' => 50, 'alert_bounce_percent' => 30])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('7', app(Settings::class)->get('dnsmail.maillog_retention_days'));

        MailLogMessage::whereKey($b->id)->update(['logged_at' => now()->subDays(8)]);
        $this->artisan('dns-mail:maillog --prune')->assertSuccessful();
        $this->assertNull(MailLogMessage::find($b->id));
    }

    public function test_dutch_and_access(): void
    {
        $this->feed();
        $admin = User::factory()->admin()->create(['locale' => 'nl']);
        $this->actingAsAdmin($admin);
        $this->get('/admin/mail-log')->assertOk()->assertSee('Maillog')->assertSee('Uitgaande mail per klant');

        $client = User::factory()->create(['customer_id' => $this->jan->id]);
        $this->actingAs($client)->get('/admin/mail-log')->assertForbidden();
    }

    public function test_command_runs_the_collector(): void
    {
        $this->pages['start'] = ['cursor' => 'c9', 'events' => [$this->ev('queued', 'DDDDDDDDDDDD04', ['from' => 'root', 'nrcpt' => 1])]];
        $this->artisan('dns-mail:maillog')->assertSuccessful();
        $this->assertSame(1, MailLogMessage::count());
        $this->assertSame('c9', app(Settings::class)->get(MailLogCollector::CURSOR_KEY));
    }
}
