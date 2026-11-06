<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Laravel;

use App\Models\Website;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\DnsMail\Console\DaneCommand;
use Rhis\DnsMail\Console\DnsCheckCommand;
use Rhis\DnsMail\Dns\Resolver;
use Rhis\DnsMail\Dns\SystemResolver;
use Rhis\DnsMail\Http\CurlHttpClient;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Mail\MailServerSettings;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\DomainProvisioner;
use Throwable;

/** Wires DNS, mail and mailboxes into the panel (Laravel package discovery). */
final class DnsMailServiceProvider extends ServiceProvider
{
    private const ROOT = __DIR__ . '/../..';

    public function register(): void
    {
        $this->mergeConfigFrom(self::ROOT . '/config/dns-mail.php', 'dns-mail');
        $this->app->singleton(HttpClient::class, static fn (): HttpClient => new CurlHttpClient());
        $this->app->singleton(DnsMailConfig::class);
        $this->app->singleton(Resolver::class, static fn (): Resolver => new SystemResolver());
        $this->app->bind(MailServerSettings::class, static fn ($app) => $app->make(DnsMailConfig::class)->server());
        $this->app->register(MailLogServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(self::ROOT . '/lang', 'dns-mail');
        $this->loadJsonTranslationsFrom(self::ROOT . '/lang');
        $this->loadViewsFrom(self::ROOT . '/resources/views', 'dns-mail');
        $this->loadMigrationsFrom(self::ROOT . '/database/migrations');
        $this->publishes([self::ROOT . '/config/dns-mail.php' => config_path('dns-mail.php')], 'dns-mail-config');

        if ($this->app->runningInConsole()) {
            $this->commands([DaneCommand::class, DnsCheckCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('dns-mail:dane')->dailyAt('04:20')->withoutOverlapping();
            $schedule->command('dns-mail:check')->everyFiveMinutes()->withoutOverlapping();
        });

        if (!class_exists(Menu::class)) {
            return; // Not inside the panel (e.g. package unit tests).
        }

        $this->registerRoutes();
        $this->registerMenus();

        // New website: create its mail domain, DKIM key and DNS right away.
        Website::created(function (Website $site): void {
            try {
                $this->app->make(DomainProvisioner::class)->forWebsite($site);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
            ->group(self::ROOT . '/routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('mail')->name('client.')
            ->group(self::ROOT . '/routes/client.php');
    }

    private function registerMenus(): void
    {
        Menu::admin()
            ->add('domains', 'Domains & mail', 'admin.mail.domains.index', icon: 'mail', section: 'Hosting', order: 35, activePatterns: ['admin.mail.domains.*'])
            ->add('dns-mail-settings', 'DNS & mail server', 'admin.mail.settings.edit', icon: 'cloud', section: 'System', order: 205, activePatterns: ['admin.mail.settings.*']);
        Menu::client()
            ->add('mail', 'Mail & DNS', 'client.mail.domains.index', icon: 'mail', order: 20, activePatterns: ['client.mail.*']);
    }
}
