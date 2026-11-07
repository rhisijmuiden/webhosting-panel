<?php

declare(strict_types=1);

namespace Rhis\Waf;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use App\Support\TeamAreas;
use App\Support\ToolsCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Rhis\Waf\Testing\FakeAgentResponses;
use Throwable;

final class WafServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/waf.php', 'waf');
        $this->app->singleton(Waf::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeAgentResponses::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'waf');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/web-firewall')->name('admin.waf.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('web-firewall')->name('client.waf.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('waf', 'Web application firewall', 'admin.waf.index', icon: 'firewall', section: 'Server', order: 117,
            activePatterns: ['admin.waf.*']);
        Menu::client()->add('waf', 'Web firewall', 'client.waf.index', icon: 'firewall', order: 31, activePatterns: ['client.waf.*']);
        ToolsCatalog::add('security', 'Web application firewall', 'admin.waf.index', 'ModSecurity with the OWASP rules: block attacks per website, exceptions and events');
        TeamAreas::extend('security', ['client.waf.*']);

        // The Security center shows a firewall card (packages/vulnerabilities includes it when present).
        View::composer('waf::admin.security-center-card', function ($view) {
            try {
                $view->with('waf', app(Waf::class)->summary());
            } catch (Throwable) {
                $view->with('waf', null);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ApplyCommand::class, Console\CollectCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:waf:collect')->everyFiveMinutes()->withoutOverlapping(30);
        });
    }
}
