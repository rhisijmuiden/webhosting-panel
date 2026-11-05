<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_settings_are_saved_with_encrypted_password(): void
    {
        $this->actingAsAdmin();
        $this->put('/admin/settings/mail', [
            'enabled' => '1', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls',
            'username' => 'panel', 'password' => 's3cret', 'from_address' => 'panel@example.com', 'from_name' => 'Panel',
            'verify_peer' => '1', 'timeout' => 20,
        ])->assertSessionHasNoErrors();

        $stored = Setting::find('mail.password');
        $this->assertTrue($stored->encrypted);
        $this->assertNotSame('s3cret', $stored->value);

        $config = app(MailConfigurator::class)->mailerConfig(app(MailConfigurator::class)->settings());
        $this->assertSame('smtp', $config['scheme']);
        $this->assertSame('smtp.example.com', $config['host']);
        $this->assertSame('s3cret', $config['password']);
        $this->assertTrue($config['require_tls']);
        $this->assertArrayNotHasKey('verify_peer', $config);

        // Leaving the password empty keeps it.
        $this->put('/admin/settings/mail', ['enabled' => '1', 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'tls', 'from_address' => 'panel@example.com', 'verify_peer' => '0']);
        $s = app(MailConfigurator::class)->settings();
        $this->assertSame('s3cret', $s['password']);
        $config = app(MailConfigurator::class)->mailerConfig($s);
        $this->assertSame('smtps', $config['scheme']);
        $this->assertFalse($config['verify_peer']);
    }

    public function test_host_is_required_for_custom_server(): void
    {
        $this->actingAsAdmin();
        $this->put('/admin/settings/mail', ['enabled' => '1', 'encryption' => 'starttls', 'from_address' => 'a@example.com'])
            ->assertSessionHasErrors(['host', 'port']);
    }

    public function test_local_server_is_the_default(): void
    {
        $config = app(MailConfigurator::class)->mailerConfig(app(MailConfigurator::class)->settings());
        $this->assertSame(['127.0.0.1', 25, false], [$config['host'], $config['port'], $config['auto_tls']]);
    }

    public function test_test_mail_reports_failure(): void
    {
        $this->actingAsAdmin();
        // Port 9 on localhost refuses connections: the error must reach the user, not crash.
        $this->put('/admin/settings/mail', ['enabled' => '1', 'host' => '127.0.0.1', 'port' => 9, 'encryption' => 'none', 'from_address' => 'a@example.com', 'timeout' => 3]);
        $this->post('/admin/settings/mail/test', ['test_to' => 'me@example.com'])->assertSessionHasErrors('test_to');
    }
}
