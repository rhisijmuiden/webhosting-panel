<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\ObjectCache\ObjectCacheManager;
use Rhis\Vulnerabilities\Scanner;
use App\Services\WpHardening\Hardening;
use Rhis\WordPress\Bulk\Console\TickCommand;
use Rhis\WordPress\DbCleanup\Cleaner;
use Rhis\WordPress\Extensions\ExtensionManager;
use Rhis\WordPress\Switches\Switches;
use Rhis\WpIntegrity\Integrity;

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
        // Actions of other WordPress features, only when that feature is installed.
        if (class_exists(ExtensionManager::class)) {
            BulkActions::register(Integrations\ManageExtensions::class);
            BulkActions::register(Integrations\InstallExtension::class);
            BulkActions::register(Integrations\DeleteExtensions::class);
        }
        if (class_exists(Switches::class)) {
            BulkActions::register(Integrations\ClearCaches::class);
            foreach (Switches::SWITCHES as $switch) {
                BulkActions::register(fn () => new Integrations\QuickSwitch(app(Switches::class), $switch));
            }
        }
        if (class_exists(Integrity::class)) {
            BulkActions::register(Integrations\IntegrityCheck::class);
        }
        if (class_exists(Hardening::class)) {
            BulkActions::register(Integrations\Hardening::class);
        }
        if (class_exists(Cleaner::class)) {
            BulkActions::register(Integrations\DatabaseCleanup::class);
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
