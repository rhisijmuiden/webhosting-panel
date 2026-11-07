<?php

declare(strict_types=1);

namespace Rhis\DiskUsage;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\TeamAreas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\DiskUsage\Console\ScanCommand;

final class DiskUsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/disk-usage.php', 'disk-usage');
        $this->app->scoped(DiskUsage::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'disk-usage');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('disk-usage:scan')->dailyAt((string) config('disk-usage.scan_at', '03:40'))->withoutOverlapping();
        });

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->name('client.')
                ->group(__DIR__.'/../routes/client.php');
        }

        // Team members with the Files area may open the explorer.
        if (class_exists(TeamAreas::class)) {
            TeamAreas::extend('files', ['client.disk-usage.*']);
        }

        Menu::admin()->add('disk-usage', 'Disk usage', 'admin.disk-usage.index', icon: 'chart', section: 'Hosting services', order: 170,
            activePatterns: ['admin.disk-usage.*']);
        Menu::client()->add('disk-usage', 'Disk usage', 'client.disk-usage.index', icon: 'chart', section: 'Files & data', order: 295,
            activePatterns: ['client.disk-usage.*'],
            visible: fn () => config('disk-usage.customer') && auth()->user()?->customer?->websites()->exists());

        // Local development and tests run without the real agent.
        $fake = function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeResponses::register($agent);
            }
        };
        if ($this->app->resolved(AgentClient::class)) {
            $fake($this->app->make(AgentClient::class));
        } else {
            $this->callAfterResolving(AgentClient::class, $fake);
        }
    }
}
