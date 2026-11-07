<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * WordPress database cleanup (revisions, transients, spam, trash, orphaned rows, table
 * optimisation). Its own files inside the toolkit package; registered by
 * WordPressServiceProvider. Migration: database/migrations (loaded by the toolkit).
 */
final class DbCleanupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Cleaner::class);
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/dbclean');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress')->name('admin.wordpress.dbclean.')
                ->group(__DIR__.'/../../routes/dbclean-admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('wordpress')->name('client.wordpress.dbclean.')
                ->group(__DIR__.'/../../routes/dbclean-client.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CollectCleanupsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wordpress:dbclean-collect')->everyFiveMinutes()->withoutOverlapping();
        });

        if (method_exists(ActivityLog::class, 'labels')) {
            ActivityLog::labels(['wordpress.db_cleanup' => 'WordPress database cleaned up']);
        }

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
