<?php

declare(strict_types=1);

namespace Rhis\Security;

use App\Auth\TrustedNetworks;
use App\Support\Menu;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/security.php', 'security');
        $this->app->singleton(LoginProtection::class);
        $this->app->singleton(TrustedNetworks::class, fn ($app) => $app->make(LoginProtection::class));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'security');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::listen(Failed::class, FailedLoginLog::class);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ApplyCommand::class]);
        }

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/security/protection')->name('admin.protection.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('security')->name('client.protection.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('protection', 'Login protection', 'admin.protection.edit', icon: 'lock', section: 'Server', order: 115,
            activePatterns: ['admin.protection.*']);
        Menu::client()->add('protection', 'Login protection', 'client.protection.index', icon: 'shield', order: 30);
    }
}
