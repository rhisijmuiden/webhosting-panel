<?php

declare(strict_types=1);

namespace Rhis\Backups;

use App\Support\Menu;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backups.php', 'backups');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backups');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/backups')->name('admin.backups.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('backups')->name('client.backups.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('backups', 'Backups', 'admin.backups.index', icon: 'backup', section: 'Server', order: 85);
        Menu::client()->add('backups', 'Backups', 'client.backups.index', icon: 'backup', order: 40,
            visible: fn () => (bool) config('backups.customer_restore'));
    }
}
