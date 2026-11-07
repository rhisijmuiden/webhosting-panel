<?php

declare(strict_types=1);

namespace Rhis\WebRules;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\TeamAreas;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Redirects & access per website. No menu item: the pages open from the
 * tiles on the domain page (client and admin).
 */
final class WebRulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/web-rules.php', 'web-rules');
        $this->app->singleton(WebRules::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'web-rules');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/web-rules')->name('admin.web-rules.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('web-rules')->name('client.web-rules.')
                ->group(__DIR__.'/../routes/client.php');
        }

        // Team members with "Website settings" may manage redirects and access too.
        if (class_exists(TeamAreas::class) && method_exists(TeamAreas::class, 'extend')) {
            TeamAreas::extend('websites', ['client.web-rules.*']);
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
