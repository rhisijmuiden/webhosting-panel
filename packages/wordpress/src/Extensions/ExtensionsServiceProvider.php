<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Plugins & themes of one WordPress install: activate, delete, install from WordPress.org, auto-updates. */
final class ExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wordpress-extensions.php', 'wordpress-extensions');
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeExtensions::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $base = dirname(__DIR__, 2);
        $this->loadJsonTranslationsFrom($base.'/lang/extensions');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress/{install}/extensions')
            ->name('admin.wordpress.extensions.')
            ->group($base.'/routes/extensions-admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('wordpress/{install}/extensions')
            ->name('client.wordpress.extensions.')
            ->group($base.'/routes/extensions-client.php');
    }
}
