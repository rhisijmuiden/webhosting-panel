<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Laravel;

use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\DnsMail\Console\MailLogCommand;
use Rhis\DnsMail\Services\MailLogSettings;

/**
 * Admin mail log: every message Postfix handled, tied to a customer.
 * Registered by DnsMailServiceProvider; kept apart so its routes, menu,
 * translations and schedule live in their own files.
 */
final class MailLogServiceProvider extends ServiceProvider
{
    private const ROOT = __DIR__ . '/../..';

    public function register(): void
    {
        $this->app->singleton(MailLogSettings::class);
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(self::ROOT . '/lang/maillog');

        if ($this->app->runningInConsole()) {
            $this->commands([MailLogCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('dns-mail:maillog')->everyMinute()->withoutOverlapping(10);
            $schedule->command('dns-mail:maillog --prune')->dailyAt('03:40')->withoutOverlapping();
        });

        if (!class_exists(Menu::class)) {
            return;
        }
        if (!$this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
                ->group(self::ROOT . '/routes/maillog.php');
        }
        Menu::admin()->add('mail-log', 'Mail log', 'admin.mail.log.index', icon: 'list', section: 'Hosting', order: 36, activePatterns: ['admin.mail.log.*']);
    }
}
