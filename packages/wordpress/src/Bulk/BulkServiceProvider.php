<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\ObjectCache\ObjectCacheManager;
use Rhis\Vulnerabilities\Scanner;
use Rhis\WordPress\Bulk\Console\TickCommand;

/** Bulk actions on many WordPress sites at once (admin only). Registered by WordPressServiceProvider. */
final class BulkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Runner::class);

        BulkActions::register(Actions\UpdateEverything::class);
        BulkActions::register(Actions\UpdateComponents::class);
        BulkActions::register(Actions\CheckForUpdates::class);
        BulkActions::register(Actions\SetWpCron::class);
        if (class_exists(Scanner::class)) {
            BulkActions::register(Actions\SecurityScan::class);
        }
        if (class_exists(ObjectCacheManager::class)) {
            BulkActions::register(Actions\FlushObjectCache::class);
        }
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/bulk');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress/bulk')->name('admin.wordpress.bulk.')
            ->group(__DIR__.'/../../routes/bulk-admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wp-bulk:tick')->everyMinute()->withoutOverlapping(30);
        });
    }
}
