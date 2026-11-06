<?php

declare(strict_types=1);

namespace Rhis\DomainAliases;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\CrmApi\Http\Middleware\AuthenticateToken;
use Rhis\CrmApi\Http\Middleware\Idempotency;
use Rhis\CrmApi\Models\Order;

final class DomainAliasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/domain-aliases.php', 'domain-aliases');
        $this->app->singleton(AliasManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'domain-aliases');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! class_exists(Menu::class)) {
            return;
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/domain-aliases')->name('admin.domain-aliases.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('domain-aliases')->name('client.domain-aliases.')
                ->group(__DIR__.'/../routes/client.php');
            if (class_exists(AuthenticateToken::class)) {
                Route::prefix('api/v1/crm')->middleware(['api', AuthenticateToken::class, Idempotency::class])
                    ->group(__DIR__.'/../routes/api.php');
            }
        }

        Menu::admin()->add('domain-aliases', 'Domain aliases', 'admin.domain-aliases.index', icon: 'globe', section: 'Hosting', order: 31);
        Menu::client()->add('domain-aliases', 'Domain aliases', 'client.domain-aliases.index', icon: 'globe', order: 12);

        // The CRM accepted or completed an upgrade order: convert the alias.
        if (class_exists(Order::class)) {
            Order::updated(function (Order $order) {
                if ($order->wasChanged('status')) {
                    $this->app->make(AliasManager::class)->orderChanged($order);
                }
            });
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
