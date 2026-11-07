<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\DatabaseUser;
use App\Models\SiteDatabase;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\FakeAgentClient;
use App\Services\Databases\DatabaseManager;
use App\Services\Databases\FakeDatabases;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Databases for customers and admins: create and delete databases and
 * database users, reset passwords, and phpMyAdmin with single sign-on.
 * Admin routes: routes/admin-databases.php; client routes: routes/client-databases.php.
 */
class DatabasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseManager::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeDatabases::register($agent);
            }
        });
    }

    public function boot(): void
    {
        Route::bind('database', fn ($value) => SiteDatabase::with('website')->findOrFail((int) $value));
        Route::bind('dbuser', fn ($value) => DatabaseUser::with('website')->findOrFail((int) $value));
        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'client'])->name('client.')->group(base_path('routes/client-databases.php'));
        }

        Menu::admin()->add('databases', 'Databases', 'admin.databases.index', icon: 'database', section: 'Hosting', order: 38,
            activePatterns: ['admin.databases.*']);
        Menu::client()->add('databases', 'Databases', 'client.databases.index', icon: 'database', section: 'Files & data', order: 295,
            activePatterns: ['client.databases.*']);

        ActivityLog::labels([
            'database.created' => 'Database created',
            'database.dropped' => 'Database deleted',
            'database.user_created' => 'Database user created',
            'database.user_updated' => 'Database user access changed',
            'database.user_dropped' => 'Database user deleted',
            'database.password_reset' => 'Database password changed',
            'database.phpmyadmin' => 'Opened phpMyAdmin',
        ]);

        // phpMyAdmin sign-on users live a few hours; the agent drops them when they expire.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(static function (): void {
                try {
                    app(AgentClient::class)->call('db.sso_cleanup');
                } catch (AgentException $e) {
                    Log::info('db.sso_cleanup failed', ['error' => $e->getMessage()]);
                }
            })->name('whp:databases:sso-cleanup')->everyTenMinutes()->withoutOverlapping();
        });
    }
}
