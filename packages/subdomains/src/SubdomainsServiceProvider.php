<?php

declare(strict_types=1);

namespace Rhis\Subdomains;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Subdomains\Console\CloneTickCommand;
use Rhis\Subdomains\Staging\Console\StagingTickCommand;
use Rhis\Subdomains\Staging\FakeResponses as StagingFakeResponses;
use Rhis\Subdomains\Staging\StagingManager;

final class SubdomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/subdomains.php', 'subdomains');
        $this->app->singleton(SubdomainManager::class);
        $this->app->singleton(CloneManager::class);
        $this->app->singleton(StagingManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'subdomains');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang/staging');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CloneTickCommand::class, StagingTickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('site-clone:tick')->everyMinute()->withoutOverlapping();
            $schedule->command('wp-staging:tick')->everyMinute()->withoutOverlapping();
        });
        if (class_exists(\App\Support\TeamAreas::class)) {
            \App\Support\TeamAreas::extend('wordpress', ['client.staging.*']);
        }

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->name('client.')
                ->group(__DIR__.'/../routes/client.php');
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
                ->group(__DIR__.'/../routes/staging-admin.php');
            Route::middleware(['web', 'auth', 'client'])->name('client.')
                ->group(__DIR__.'/../routes/staging-client.php');
        }

        // Local development and tests run without the real agent.
        $fake = function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeResponses::register($agent);
                StagingFakeResponses::register($agent);
            }
        };
        if ($this->app->resolved(AgentClient::class)) {
            $fake($this->app->make(AgentClient::class));
        } else {
            $this->callAfterResolving(AgentClient::class, $fake);
        }
    }
}
