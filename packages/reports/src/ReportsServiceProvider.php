<?php

declare(strict_types=1);

namespace Rhis\Reports;

use App\Support\Menu;
use App\Support\ToolsCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Reports\Console\SendCommand;

final class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reports.php', 'reports');
        $this->app->singleton(Options::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reports');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/reports')->name('admin.reports.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('reports')->name('client.reports.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('reports', 'Monthly reports', 'admin.reports.index', icon: 'chart', section: 'Hosting services', order: 170,
            activePatterns: ['admin.reports.*']);
        Menu::client()->add('reports', 'Monthly report', 'client.reports.index', icon: 'chart', section: 'Account', order: 410,
            activePatterns: ['client.reports.*']);
        ToolsCatalog::add('mail', 'Monthly reports', 'admin.reports.index', 'The monthly mail that shows customers what their hosting did');

        if ($this->app->runningInConsole()) {
            $this->commands([SendCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Hourly: the command itself checks the configured day and time, and never sends a month twice.
            $schedule->command('whp:reports:send')->hourlyAt(7)->withoutOverlapping(120);
        });
    }
}
