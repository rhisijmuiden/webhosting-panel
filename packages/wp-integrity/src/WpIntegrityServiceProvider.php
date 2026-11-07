<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\TeamAreas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\WpIntegrity\Console\CheckCommand;
use Rhis\WpIntegrity\Models\IntegrityReport;

final class WpIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wp-integrity.php', 'wp-integrity');
        $this->app->scoped(Integrity::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'wp-integrity');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('wp-integrity:check')->dailyAt((string) config('wp-integrity.check_at', '04:45'))->withoutOverlapping(180);
        });

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->name('client.')
                ->group(__DIR__.'/../routes/client.php');
        }

        // Team members with the WordPress area may use the integrity check too.
        if (class_exists(TeamAreas::class)) {
            TeamAreas::extend('wordpress', ['client.wp-integrity.*']);
        }

        // Right after the Security center; customers reach it from their WordPress page and security alerts.
        Menu::admin()->add('wp-integrity', 'File integrity', 'admin.wp-integrity.index', icon: 'shield', section: 'Server management', order: 315,
            activePatterns: ['admin.wp-integrity.*'],
            badge: fn () => IntegrityReport::where('status', 'changed')->count() ?: null);

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
