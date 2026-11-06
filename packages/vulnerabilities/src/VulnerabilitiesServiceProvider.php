<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Vulnerabilities\Console\NotifyCommand;
use Rhis\Vulnerabilities\Console\ScanCommand;
use Rhis\Vulnerabilities\Console\SyncCommand;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\Vulnerabilities\Testing\FakeAgentResponses;
use Rhis\WordPress\Models\WordPressInstall;
use Throwable;

final class VulnerabilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vulnerabilities.php', 'vulnerabilities');
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeAgentResponses::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vulnerabilities');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/security-overview')->name('admin.vulns.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('security-alerts')->name('client.vulns.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('security-overview', 'Security overview', 'admin.vulns.index', icon: 'shield', section: 'Server', order: 105);
        Menu::client()->add('security-alerts', 'Security alerts', 'client.vulns.index', icon: 'shield', order: 16);

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class, SyncCommand::class, NotifyCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:vuln:sync')->dailyAt((string) config('vulnerabilities.sync_time'))->withoutOverlapping(60);
            $schedule->command('whp:vuln:scan')->dailyAt((string) config('vulnerabilities.scan_time'))->withoutOverlapping(180);
        });

        // After a WordPress update, check that install again so fixed problems are closed straight away.
        WordPressInstall::saved(function (WordPressInstall $install) {
            if (! $install->wasChanged(['version', 'plugins', 'themes'])
                || ! Finding::open()->where('wordpress_install_id', $install->id)->exists()) {
                return;
            }
            try {
                app(Scanner::class)->scanInstall($install);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
