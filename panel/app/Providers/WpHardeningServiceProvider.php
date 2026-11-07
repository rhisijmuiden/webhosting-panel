<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Services\WpHardening\FakeResponses;
use App\Services\WpHardening\Hardening;
use App\Support\TeamAreas;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * One-click WordPress security hardening per install (docs/wp-hardening.md).
 * No menu item: the page opens from the card on the WordPress install page.
 */
class WpHardeningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('wp-hardening.php'), 'wp-hardening');
        $this->app->singleton(Hardening::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            // The admin routes are routes/admin-wp-hardening.php (joined by bootstrap/app.php).
            Route::middleware(['web', 'auth', 'client'])->name('client.')->group(base_path('routes/client-wp-hardening.php'));
        }

        // Team members with "Website settings" may harden WordPress too.
        TeamAreas::extend('websites', ['client.wp-hardening.*']);

        ActivityLog::labels([
            'wp_hardening.applied' => 'WordPress hardening changed',
            'wp_hardening.salts_refreshed' => 'WordPress security keys renewed',
            'wp_hardening.salts_restored' => 'WordPress security keys put back',
        ]);

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
