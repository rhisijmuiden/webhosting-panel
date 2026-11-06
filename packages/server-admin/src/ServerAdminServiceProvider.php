<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
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

        // Settings > Server management. The settings overview places it; until then it sits under System.
        Menu::admin()->add('server-management', 'Server management', 'admin.server.index', icon: 'server', section: 'System', order: 525);

        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:server:tick')->everyMinute()->withoutOverlapping(60);
        });
    }
}
