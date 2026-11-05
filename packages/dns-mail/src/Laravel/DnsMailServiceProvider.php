<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Laravel;

use Illuminate\Support\ServiceProvider;
use Rhis\DnsMail\Cloudflare\CloudflareClient;
use Rhis\DnsMail\Dns\ZoneSync;
use Rhis\DnsMail\Http\CurlHttpClient;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Mail\MailDnsProfile;
use Rhis\DnsMail\Mail\MailServerSettings;
use RuntimeException;

/**
 * Wires the package into the panel (Laravel package discovery).
 * Screens and routes are added once the panel layout from phase 2 is in.
 */
final class DnsMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/dns-mail.php', 'dns-mail');

        $this->app->singleton(HttpClient::class, static fn (): HttpClient => new CurlHttpClient());

        $this->app->bind(CloudflareClient::class, function ($app): CloudflareClient {
            $token = (string) $app['config']->get('dns-mail.cloudflare_token');
            if ($token === '') {
                throw new RuntimeException('No Cloudflare API token configured');
            }

            return new CloudflareClient($app->make(HttpClient::class), $token);
        });

        $this->app->bind(ZoneSync::class, static fn ($app): ZoneSync => new ZoneSync($app->make(CloudflareClient::class)));

        $this->app->singleton(MailServerSettings::class, static function ($app): MailServerSettings {
            $c = $app['config']->get('dns-mail.server');

            return new MailServerSettings(
                mailHost: (string) ($c['mail_host'] ?: gethostname()),
                ipv4: $c['ipv4'] ?: null,
                ipv6: $c['ipv6'] ?: null,
                relaySpfIncludes: array_values($c['relay_spf_includes'] ?? []),
                webmailHost: $c['webmail_host'] ?: null,
                brandName: (string) ($c['brand'] ?? 'RHIS'),
            );
        });

        $this->app->bind(MailDnsProfile::class, static fn ($app): MailDnsProfile => new MailDnsProfile($app->make(MailServerSettings::class)));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../../lang', 'dns-mail');
        $this->publishes([__DIR__ . '/../../config/dns-mail.php' => config_path('dns-mail.php')], 'dns-mail-config');
    }
}
