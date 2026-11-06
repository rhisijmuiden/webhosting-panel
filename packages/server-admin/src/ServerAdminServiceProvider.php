<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\ToolsCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\ServerAdmin\Console\TickCommand;
use Rhis\ServerAdmin\Testing\FakeAgentResponses;

final class ServerAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/server-admin.php', 'server-admin');
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'server-admin');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/server')->name('admin.server.')
            ->group(__DIR__.'/../routes/admin.php');
        // The screens' script (the panel's CSP allows no inline scripts).
        Route::get('/server-admin-assets/server.js', fn () => response()->file(__DIR__.'/../resources/js/server.js', [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600',
        ]))->name('server-admin.assets.js');

        // Reached through Tools & settings (AppServiceProvider places it under 'tools').
        Menu::admin()->add('server-management', 'Server management', 'admin.server.index', icon: 'server', section: 'Server management', order: 350);
        ToolsCatalog::add('server', 'OS updates & reboot', 'admin.server.index', 'Debian updates, security patches with CVE scores and a safe reboot');
        ToolsCatalog::add('server', 'Maintenance window', 'admin.server.maintenance', 'Nightly updates, automatic reboots, critical CVEs and PHP end of life');
        ToolsCatalog::add('server', 'Scheduled tasks', 'admin.server.tasks', 'Database optimize, cleanup and health checks');
        ToolsCatalog::add('server', 'PHP versions', 'admin.server.php', 'Support dates, moving websites and installing or removing versions');

        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:server:tick')->everyMinute()->withoutOverlapping(60);
        });
    }
}
