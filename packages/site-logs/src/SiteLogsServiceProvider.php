<?php

declare(strict_types=1);

namespace Rhis\SiteLogs;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\TeamAreas;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteLogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/site-logs.php', 'site-logs');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'site-logs');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/logs')->name('admin.site-logs.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('logs')->name('client.site-logs.')
                ->group(__DIR__.'/../routes/client.php');
        }

        Menu::admin()->add('site-logs', 'Website logs', 'admin.site-logs.index', icon: 'list', section: 'Additional services', order: 245);
        Menu::client()->add('site-logs', 'Logs', 'client.site-logs.index', icon: 'list', section: 'Websites', order: 145,
            visible: fn () => (bool) config('site-logs.customer_access'));

        // Team members with "Website settings" may read the logs of the websites they were given.
        if (class_exists(TeamAreas::class)) {
            TeamAreas::extend('websites', ['client.site-logs.*']);
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
