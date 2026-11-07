<?php

declare(strict_types=1);

namespace Rhis\Apps;

use App\Support\Menu;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AppsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/apps.php', 'apps');
        $this->app->singleton(AppInstaller::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'apps');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang/plugin-sets');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/apps')->name('admin.apps.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('apps')->name('client.apps.')
                ->group(__DIR__.'/../routes/client.php');
        }

        \App\Models\ActivityLog::labels([
            'plugin_set.created' => 'WordPress plugin set created',
            'plugin_set.updated' => 'WordPress plugin set changed',
            'plugin_set.deleted' => 'WordPress plugin set deleted',
        ]);

        Menu::admin()->add('apps', 'App installer', 'admin.apps.index', icon: 'apps', section: 'Hosting', order: 32);
        Menu::client()->add('apps', 'Apps', 'client.apps.index', icon: 'apps', order: 15,
            visible: fn () => (bool) config('apps.customer_install'));
    }
}
