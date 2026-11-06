<?php

declare(strict_types=1);

namespace Rhis\Git;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Git\Console\CollectCommand;
use Rhis\Git\Models\GitRepository;

final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/git.php', 'git');
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeGit::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'git');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::bind('repo', fn ($value) => GitRepository::with('website')->findOrFail((int) $value));
        Route::middleware(['api', 'throttle:60,1'])->group(__DIR__.'/../routes/webhook.php');
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/git')->name('admin.git.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('git')->name('client.git.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('git', 'Git', 'admin.git.index', icon: 'git', section: 'Hosting', order: 34);
        Menu::client()->add('git', 'Git', 'client.git.index', icon: 'git', order: 17,
            visible: fn () => (bool) config('git.customer_access'));

        if ($this->app->runningInConsole()) {
            $this->commands([CollectCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whp:git:collect')->everyMinute()->withoutOverlapping(10);
        });
    }
}
