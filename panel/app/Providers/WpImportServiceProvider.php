<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\WpImport;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Services\WpImport\FakeWpImport;
use App\Services\WpImport\WpImportManager;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * WordPress import wizard: a customer (or admin) uploads a zip of the WordPress
 * folder and a database export, checks them, confirms the changes and imports.
 * Admin routes: routes/admin-wp-import.php; client routes: routes/client-wp-import.php.
 */
class WpImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WpImportManager::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeWpImport::register($agent);
            }
        });
    }

    public function boot(): void
    {
        Route::bind('wpImport', fn ($value) => WpImport::with('website')->findOrFail((int) $value));
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'client'])->name('client.')->group(base_path('routes/client-wp-import.php'));
        }

        \App\Support\TeamAreas::extend('wordpress', ['client.wp-import.*']);

        Menu::admin()->add('wp-import', 'Import WordPress', 'admin.wp-import.index', icon: 'wordpress', section: 'Hosting', order: 47,
            activePatterns: ['admin.wp-import.*']);
        Menu::client()->add('wp-import', 'Import WordPress', 'client.wp-import.index', icon: 'wordpress', section: 'Websites', order: 125,
            activePatterns: ['client.wp-import.*'], visible: fn () => (bool) config('wp-import.customer_access'));

        ActivityLog::labels([
            'wordpress.import_started' => 'WordPress import started',
            'wordpress.imported' => 'WordPress website imported',
            'wordpress.import_failed' => 'WordPress import failed',
        ]);

        // Imports finish even when nobody keeps the page open: the database is registered and the uploads removed.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(static function (): void {
                $manager = app(WpImportManager::class);
                WpImport::with('website')->whereIn('status', ['checking', 'running'])->get()->each(fn ($i) => $manager->sync($i));
                // Uploads of imports nobody finished are removed after two days.
                WpImport::whereIn('status', ['upload', 'checked', 'failed'])->where('updated_at', '<', now()->subDays(2))
                    ->get()->each(fn ($i) => $manager->discard($i));
            })->name('whp:wp-import:tick')->everyMinute()->withoutOverlapping();
        });
    }
}
