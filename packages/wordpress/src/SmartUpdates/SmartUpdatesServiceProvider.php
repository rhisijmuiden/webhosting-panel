<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\WordPress\SmartUpdates\Console\SmartUpdatesCommand;

/**
 * Smart updates: scheduled WordPress updates in the maintenance window with a
 * before/after screenshot comparison and rollback. Lives inside the WordPress
 * package but keeps its own routes, views, translations and migration, so the
 * toolkit's own files stay untouched.
 */
final class SmartUpdatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/smart-updates.php', 'wp-smart-updates');
        $this->app->singleton(Options::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeSmartUpdates::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/smart-updates');

        // Below /admin/wordpress and /wordpress, so the WordPress menu item stays active.
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress/smart-updates')->name('admin.wordpress.smart.')
            ->group(__DIR__.'/../../routes/smart-updates-admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('wordpress/smart-updates')->name('client.wordpress.smart.')
            ->group(__DIR__.'/../../routes/smart-updates-client.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SmartUpdatesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wordpress:smart-updates')->everyMinute()->withoutOverlapping(10);
        });
    }
}
