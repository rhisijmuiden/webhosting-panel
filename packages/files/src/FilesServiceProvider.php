<?php

declare(strict_types=1);

namespace Rhis\Files;

use App\Http\Middleware\EnsureTwoFactor;
use App\Services\Agent\AgentClient;
use App\Support\Menu;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Files\Http\AdminFileController;
use Rhis\Files\Http\ClientFileController;
use Rhis\Files\Http\TerminalAuthController;

final class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/files.php', 'files');
        $this->app->singleton(FileManager::class);
        if (env('WHP_FILES_DEV_SANDBOX') && config('panel.agent_driver') === 'fake' && ! $this->app->runningUnitTests()) {
            $this->app->extend(AgentClient::class, fn ($agent) => new DevSandboxAgent($agent));
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'files');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // The editor saves files exactly as typed: no trimming, no empty string to null.
        // Global middleware runs before routing, so match the path rather than the route name.
        $isWrite = fn ($request) => $request->isMethod('PUT') && $request->is('files/*/write', 'admin/files/*/write');
        TrimStrings::skipWhen($isWrite);
        ConvertEmptyStringsToNull::skipWhen($isWrite);

        $routes = __DIR__.'/../routes/files.php';
        Route::middleware(['web', 'auth', 'client'])->prefix('files')->name('client.files.')
            ->group(function () use ($routes) {
                $controller = ClientFileController::class;
                require $routes;
            });
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/files')->name('admin.files.')
            ->group(function () use ($routes) {
                $controller = AdminFileController::class;
                require $routes;
            });
        // Nginx auth_request for /terminal/<token>/ (answers 204, 401 or 403, never a redirect).
        Route::middleware('web')->withoutMiddleware(EnsureTwoFactor::class)
            ->get('/terminal/auth', TerminalAuthController::class)->name('files.terminal.auth');

        Menu::client()->add('files', 'File manager', 'client.files.index', icon: 'code', order: 14,
            activePatterns: ['client.files.*'],
            visible: fn () => config('files.enabled') && auth()->user()?->customer?->websites()->exists());
        Menu::admin()->add('files', 'File manager', 'admin.files.index', icon: 'code', section: 'Hosting', order: 25,
            activePatterns: ['admin.files.*']);
    }
}
