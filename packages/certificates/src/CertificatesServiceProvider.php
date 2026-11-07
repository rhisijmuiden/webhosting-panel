<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\TeamAreas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Certificates\Console\CheckCertificates;

final class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certificates.php', 'certificates');
        $this->app->singleton(CertificateManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'certificates');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCertificates::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('certificates:check')->dailyAt((string) config('certificates.check_at', '06:10'))->withoutOverlapping();
        });

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/certificates')->name('admin.certificates.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('certificates')->name('client.certificates.')
                ->group(__DIR__.'/../routes/client.php');
        }

        Menu::admin()->add('certificates', 'SSL certificates', 'admin.certificates.index', icon: 'lock', section: 'Hosting', order: 33);
        Menu::client()->add('certificates', 'SSL certificates', 'client.certificates.index', icon: 'lock', section: 'Websites', order: 137);
        if (class_exists(TeamAreas::class) && method_exists(TeamAreas::class, 'extend')) {
            TeamAreas::extend('security', ['client.certificates.*']);
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
