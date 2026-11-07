<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Mail\WpMailSetting;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WordPressMailTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null, array $plugins = []): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.1', 'plugins' => $plugins]);
    }

    private function calls(string $action): array
    {
        return collect($this->agent()->calls)->where('action', $action)->values()->all();
    }

    public function test_new_installs_are_switched_over_automatically_but_not_with_an_smtp_plugin_or_on_staging(): void
    {
        $plain = $this->install('klant.nl');
        $smtp = $this->install('shop.nl', plugins: [['name' => 'wp-mail-smtp', 'title' => 'WP Mail SMTP', 'status' => 'active', 'version' => '4.1', 'update' => false]]);
        $staging = $this->install('staging.klant.nl');

        $this->artisan('whp:wordpress:mail-sync')->assertSuccessful();

        $s = WpMailSetting::where('wordpress_install_id', $plain->id)->first();
        $this->assertTrue($s->enabled);
        $this->assertNotNull($s->applied_at);
        $apply = $this->calls('wp.mail_apply')[0]['params'];
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'enabled' => true, 'from_email' => 'noreply@klant.nl', 'from_name' => '',
            'force_from' => true, 'domains' => ['klant.nl']], $apply);

        $this->assertFalse(WpMailSetting::where('wordpress_install_id', $smtp->id)->first()->enabled);
        $this->assertSame('smtp_plugin', WpMailSetting::where('wordpress_install_id', $smtp->id)->first()->reason);
        $this->assertSame('staging', WpMailSetting::where('wordpress_install_id', $staging->id)->first()->reason);
        $this->assertCount(1, $this->calls('wp.mail_apply'));

        // A second run leaves decided installs alone.
        $this->artisan('whp:wordpress:mail-sync')->assertSuccessful();
        $this->assertCount(1, $this->calls('wp.mail_apply'));
    }

    public function test_server_without_website_mail_is_retried_after_the_update(): void
    {
        $install = $this->install();
        $this->agent()->respond('wp.mail_status', ['enabled' => false, 'staging' => false, 'smtp' => false]);
        $this->artisan('whp:wordpress:mail-sync')->assertSuccessful();
        $this->assertSame('unavailable', WpMailSetting::first()->reason);
        $this->assertFalse(WpMailSetting::first()->enabled);

        $this->agent()->respond('wp.mail_status', ['enabled' => false, 'staging' => false, 'smtp' => true]);
        $this->artisan('whp:wordpress:mail-sync')->assertSuccessful();
        $this->assertTrue(WpMailSetting::where('wordpress_install_id', $install->id)->first()->enabled);
    }

    public function test_auto_off_only_records_the_install(): void
    {
        config(['wp-mail.auto' => false]);
        $this->install();
        $this->artisan('whp:wordpress:mail-sync')->assertSuccessful();
        $this->assertFalse(WpMailSetting::first()->enabled);
        $this->assertNull(WpMailSetting::first()->reason);
        $this->assertCount(0, $this->calls('wp.mail_apply'));
    }

    public function test_admin_page_change_sender_and_send_a_test(): void
    {
        $install = $this->install();
        \Rhis\DnsMail\Models\MailDomain::updateOrCreate(['domain' => 'klant.nl'], ['customer_id' => $install->website->customer_id,
            'website_id' => $install->website_id, 'dkim_public_key' => 'MIIB']);
        \Rhis\DnsMail\Models\MailDomain::create(['domain' => 'merk.nl', 'customer_id' => $install->website->customer_id,
            'dkim_public_key' => 'MIIC', 'provider' => 'microsoft365', 'server_sends' => false]);
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}")->assertOk()->assertSee('Website mail')
            ->assertSee(route('admin.wordpress.mail.show', $install), false);
        $this->get("/admin/wordpress/{$install->id}/mail")->assertOk()
            ->assertSee('Not through this server')->assertSee('merk.nl')->assertSee('Send test message');

        $this->put("/admin/wordpress/{$install->id}/mail", ['enabled' => 1, 'from_local' => 'info', 'from_domain' => 'merk.nl',
            'from_name' => 'Merk', 'force_from' => 1])->assertSessionHas('status');
        $s = WpMailSetting::first();
        $this->assertSame('info@merk.nl', $s->from_email);
        $apply = $this->calls('wp.mail_apply')[0]['params'];
        $this->assertSame('info@merk.nl', $apply['from_email']);
        $this->assertSame(['klant.nl', 'merk.nl'], $apply['domains']);
        $this->assertTrue(ActivityLog::where('action', 'wordpress.mail_on')->exists());

        // Microsoft 365 domain without "websites send mail": the page says SPF needs it.
        $this->get("/admin/wordpress/{$install->id}/mail")->assertOk()->assertSee('Through this server')
            ->assertSee('Deliverability of merk.nl')->assertSee('Websites on this server send mail for this domain')
            ->assertSee('New order #1042')->assertSee('Could not connect to SMTP host');

        // A domain of someone else is refused.
        $this->put("/admin/wordpress/{$install->id}/mail", ['enabled' => 1, 'from_local' => 'x', 'from_domain' => 'gmail.com'])
            ->assertSessionHasErrors('from_domain');
        $this->put("/admin/wordpress/{$install->id}/mail", ['enabled' => 1, 'from_local' => 'a b', 'from_domain' => 'klant.nl'])
            ->assertSessionHasErrors('from_local');

        $this->post("/admin/wordpress/{$install->id}/mail/test", ['to' => 'jan@example.org'])
            ->assertSessionHas('status', 'Test message sent to jan@example.org.');
        $s->refresh();
        $this->assertTrue($s->last_test_ok);
        $this->assertSame('4XkQ3b1QwPz7', $s->last_test_queue_id);
        $this->assertSame('jan@example.org', $this->calls('wp.mail_test')[0]['params']['to']);
        $this->get("/admin/wordpress/{$install->id}/mail")->assertSee('4XkQ3b1QwPz7');

        // Off again: the plugin is taken out.
        $this->put("/admin/wordpress/{$install->id}/mail", ['enabled' => 0])->assertSessionHas('status');
        $this->assertFalse($s->fresh()->enabled);
        $this->assertFalse(collect($this->calls('wp.mail_apply'))->last()['params']['enabled']);
    }

    public function test_failed_apply_and_failed_test_are_shown(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.mail_apply', new AgentException('conflict', 'This is a staging copy: its mail is held back and stays that way'));
        $this->put("/admin/wordpress/{$install->id}/mail", ['enabled' => 1, 'from_local' => 'noreply', 'from_domain' => 'klant.nl'])
            ->assertSessionHasErrors('agent');
        $this->assertFalse(WpMailSetting::first()->enabled);

        $this->agent()->respond('wp.mail_test', ['ok' => false, 'error' => 'SMTP Error: Could not connect to SMTP host.']);
        $this->post("/admin/wordpress/{$install->id}/mail/test", ['to' => 'jan@example.org'])->assertSessionHasErrors('test');
        $this->assertSame('SMTP Error: Could not connect to SMTP host.', WpMailSetting::first()->last_test_error);
    }

    public function test_client_manages_own_install_only_and_sees_dutch(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mine.nl', $mine);
        $other = $this->install('other.nl');
        $user = User::factory()->create(['customer_id' => $mine->id, 'locale' => 'nl']);
        $this->actingAs($user);

        $this->get("/wordpress/{$install->id}/mail")->assertOk()->assertSee('Websitemail')->assertSee('Testbericht versturen');
        $this->get("/wordpress/{$other->id}/mail")->assertNotFound();
        $this->put("/wordpress/{$other->id}/mail", ['enabled' => 0])->assertNotFound();
        $this->post("/wordpress/{$other->id}/mail/test", ['to' => 'x@example.org'])->assertNotFound();

        $this->put("/wordpress/{$install->id}/mail", ['enabled' => 1, 'from_local' => 'noreply', 'from_domain' => 'mine.nl'])->assertSessionHas('status');
        $this->assertNull(WpMailSetting::first()->from_email); // the default sender is not stored
        $this->get("/websites/{$install->website_id}?tab=wordpress")->assertOk()->assertSee(route('client.wordpress.mail.show', $install), false);

        config(['wp-mail.customer' => false]);
        $this->post("/wordpress/{$install->id}/mail/test", ['to' => 'x@example.org'])->assertForbidden();
        $this->get("/wordpress/{$install->id}/mail")->assertOk()->assertDontSee('Testbericht versturen</button>', false);
    }
}
