<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\ToolsCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Monitoring\Console\RunCommand;
use Rhis\Monitoring\Testing\FakeAgentResponses;

final class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitoring.php', 'monitoring');
        $this->app->singleton(Options::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeAgentResponses::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monitoring');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/monitoring')->name('admin.monitoring.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('uptime')->name('client.monitoring.')
            ->group(__DIR__.'/../routes/client.php');
        // The public status page: no login, nothing about single customers.
        Route::middleware(['web'])->group(__DIR__.'/../routes/public.php');

        Menu::admin()->add('monitoring', 'Monitoring', 'admin.monitoring.index', icon: 'activity', section: 'Server', order: 108);
        Menu::client()->add('status', 'Status', 'client.monitoring.index', icon: 'activity', order: 18);
        // Team members with access to website settings also see the status page of the websites.
        if (method_exists(\App\Support\TeamAreas::class, 'extend')) {
            \App\Support\TeamAreas::extend('websites', ['client.monitoring.*']);
        }
        ToolsCatalog::add('server', 'Monitoring & alerts', 'admin.monitoring.alerts', 'Alerts by mail and Microsoft Teams, thresholds and the status page');

        if ($this->app->runningInConsole()) {
            $this->commands([RunCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:monitor:run')->everyMinute()->withoutOverlapping(15);
        });

        // Uptime per website for the monthly customer report (packages/reports), when that package is there.
        if (class_exists(\Rhis\Reports\Reports::class)) {
            \Rhis\Reports\Reports::provide('uptime', fn ($customer, $from, $to) => Uptime::report($customer, $from, $to));
        }
    }
}
