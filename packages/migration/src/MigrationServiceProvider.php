<?php

declare(strict_types=1);

namespace Rhis\Migration;

use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Migration\Console\TickCommand;

final class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/migration.php', 'migration');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'migration');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang/wizard');

        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('migration:tick')->everyMinute()->withoutOverlapping(10);
        });

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/migration')->name('admin.migration.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/migration')->name('admin.migration.')
                ->group(__DIR__.'/../routes/wizard.php');
        }
        Menu::admin()->add('migration', 'Migration', 'admin.migration.index', icon: 'migrate', section: 'Server', order: 87,
            activePatterns: ['admin.migration.*']);
    }
}
