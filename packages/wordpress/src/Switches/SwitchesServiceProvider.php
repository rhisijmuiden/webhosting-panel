<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Rhis\WordPress\Switches\Console\ExpireDebugCommand;

/**
 * WordPress quick switches: maintenance mode, debug mode with a debug.log viewer,
 * search engine indexing and clearing every cache. Lives inside the WordPress
 * package (registered by WordPressServiceProvider) with its own routes, views,
 * translations and migration.
 */
final class SwitchesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/switches.php', 'wordpress-switches');
        $this->app->singleton(Switches::class);
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/switches');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress')->name('admin.wordpress.')
            ->group(__DIR__.'/../../routes/switches.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('wordpress')->name('client.wordpress.')
            ->group(__DIR__.'/../../routes/switches.php');

        // The card on the install page reads the switches fresh from WordPress (once per request).
        View::composer('wordpress::switches.card', function ($view) {
            $data = $view->getData();
            if (isset($data['switches'])) {
                return;
            }
            $state = null;
            $error = null;
            try {
                $state = $this->app->make(Switches::class)->state($data['install'], true);
            } catch (\App\Services\Agent\AgentException $e) {
                $error = $e->getMessage();
                $state = $this->app->make(Switches::class)->stored($data['install']);
            }
            $view->with(['switches' => $state, 'switchesError' => $error]);
        });
        // The row on the domain page uses the stored state (read again when it is old).
        View::composer('wordpress::switches.compact', function ($view) {
            $data = $view->getData();
            try {
                $state = $this->app->make(Switches::class)->state($data['i']);
            } catch (\App\Services\Agent\AgentException) {
                $state = $this->app->make(Switches::class)->stored($data['i']);
            }
            $view->with('switches', $state);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireDebugCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wordpress:debug-expire')->everyFiveMinutes()->withoutOverlapping();
        });

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
