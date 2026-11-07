<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\WordPress\Mail\Console\SyncCommand;

/** WordPress mail through this server: own routes, views, translations and schedule (registered by WordPressServiceProvider). */
final class WpMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wp-mail.php', 'wp-mail');
        $this->app->singleton(WebsiteMail::class);
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/mail');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress')->name('admin.wordpress.')
                ->group(__DIR__.'/../../routes/mail-admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('wordpress')->name('client.wordpress.')
                ->group(__DIR__.'/../../routes/mail-client.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:wordpress:mail-sync')->everyFifteenMinutes()->withoutOverlapping(30);
        });

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
