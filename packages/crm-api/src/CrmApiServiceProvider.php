<?php

declare(strict_types=1);

namespace Rhis\CrmApi;

use App\Models\Customer;
use App\Models\Website;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Rhis\CrmApi\Console\WatchCommand;
use Rhis\CrmApi\Http\Middleware\AuthenticateToken;
use Rhis\CrmApi\Http\Middleware\Idempotency;
use Rhis\CrmApi\Services\Catalog;
use Rhis\CrmApi\Services\Subscriptions;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Present;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CrmApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crm-api.php', 'crm-api');
        $this->app->singleton(Webhooks::class);
        $this->app->singleton(Catalog::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'crm-api');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::prefix('api/v1/crm')->middleware(['api', AuthenticateToken::class, Idempotency::class])
            ->group(__DIR__.'/../routes/api.php');
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/settings/crm-api')->name('admin.crm-api.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('shop')->name('client.shop.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('crm-api', 'CRM API', 'admin.crm-api.index', icon: 'code', section: 'System', order: 205);
        Menu::client()->add('shop', 'Order', 'client.shop.index', icon: 'package', order: 60,
            visible: fn () => $this->app->make(Catalog::class)->products() !== []);

        $this->renderApiErrors();

        // Finish the provisioning job when ProvisionWebsite is done with the site.
        Website::updated(function (Website $website) {
            if ($website->wasChanged('status')) {
                Subscriptions::finishProvisioning($website);
            }
        });
        // Tell the CRM when a customer changes their own details in the panel.
        Customer::updated(function (Customer $customer) {
            if ($customer->crm_id && ! request()->attributes->has('crm_token') && $customer->wasChanged(['name', 'company', 'email', 'phone', 'locale'])) {
                $this->app->make(Webhooks::class)->dispatch('customer.updated', ['customer' => Present::customer($customer)]);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([WatchCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('crm-api:watch')->hourly()->withoutOverlapping());
    }

    /** Every error under /api/v1/crm has the OpenAPI Error shape. */
    private function renderApiErrors(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }
        $isApi = fn (Request $r) => $r->is('api/v1/crm', 'api/v1/crm/*');

        $handler->renderable(fn (ApiError $e, Request $r) => $e->render());
        $handler->renderable(function (ValidationException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiError::response(422, 'validation_failed', 'The given data was invalid', $e->errors()) : null;
        });
        $handler->renderable(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            if (! $isApi($r)) {
                return null;
            }
            $status = $e->getStatusCode();
            $code = match ($status) {
                404 => 'not_found', 405 => 'method_not_allowed', 429 => 'rate_limited', 403 => 'forbidden', default => 'http_error'
            };

            return ApiError::response($status, $code, $status === 404 ? 'Not found' : ($e->getMessage() ?: 'Error'));
        });
        $handler->renderable(function (Throwable $e, Request $r) use ($isApi) {
            if (! $isApi($r) || config('app.debug')) {
                return null;
            }
            report($e);

            return ApiError::response(500, 'internal', 'Internal error');
        });
    }
}
