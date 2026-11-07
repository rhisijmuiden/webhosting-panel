<?php

declare(strict_types=1);

namespace Rhis\WordPress;

use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\WordPress\Console\CollectCommand;
use Rhis\WordPress\Console\ScanCommand;
use Rhis\WordPress\Models\WordPressInstall;

final class WordPressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wordpress.php', 'wordpress');
        // Quick switches (maintenance, debug + debug.log, indexing, clear cache): own routes, views and translations.
        $this->app->register(Switches\SwitchesServiceProvider::class);
        $this->app->register(Bulk\BulkServiceProvider::class);
        $this->app->register(SmartUpdates\SmartUpdatesServiceProvider::class);
        $this->app->register(SearchReplace\SearchReplaceServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'wordpress');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress')->name('admin.wordpress.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('wordpress')->name('client.wordpress.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('wordpress', 'WordPress', 'admin.wordpress.index', icon: 'wordpress', section: 'Server', order: 112,
            badge: null);
        Menu::client()->add('wordpress', 'WordPress', 'client.wordpress.index', icon: 'wordpress', order: 15,
            visible: fn () => config('wordpress.customer_toolkit') && ($id = auth()->user()?->customer_id)
                && WordPressInstall::forCustomer($id)->exists());

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class, CollectCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wordpress:scan')->dailyAt((string) config('wordpress.scan_time'))->withoutOverlapping(120);
            $schedule->command('whp:wordpress:collect')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
