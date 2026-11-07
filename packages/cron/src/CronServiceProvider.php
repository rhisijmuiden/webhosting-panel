<?php

declare(strict_types=1);

namespace Rhis\Cron;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\Cron\Console\SyncCommand;
use Rhis\Cron\Models\CronJob;

final class CronServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cron.php', 'cron');
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeCron::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cron');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
        }
        $this->callAfterResolving(Scheduler::class, function (Scheduler $schedule) {
            $schedule->command('whp:cron:sync')->everyMinute()->withoutOverlapping(5);
        });

        Route::bind('cronjob', fn ($value) => CronJob::with('website')->findOrFail((int) $value));
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/cron')->name('admin.cron.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('cron')->name('client.cron.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('cron', 'Cron jobs', 'admin.cron.index', icon: 'clock', section: 'Hosting', order: 37,
            activePatterns: ['admin.cron.*']);
        Menu::client()->add('cron', 'Cron jobs', 'client.cron.index', icon: 'clock', order: 19,
            activePatterns: ['client.cron.*'], visible: fn () => self::clientVisible());

        // Team members who may change website settings may also manage its cron jobs;
        // a limited team member only sees the jobs of their own websites.
        if (class_exists(\App\Support\TeamAreas::class)) {
            \App\Support\TeamAreas::extend('websites', ['client.cron.*']);
        }
        if (class_exists(\App\Services\Team\WebsiteScope::class)) {
            CronJob::addGlobalScope(new \App\Services\Team\WebsiteScope);
        }

        $this->hooks();
    }

    /** Customers see the menu item when they have a job, or a website whose package allows one. */
    private static function clientVisible(): bool
    {
        $user = auth()->user();
        if (! config('cron.customer_access') || ! $user?->customer_id) {
            return false;
        }

        return CronJob::forCustomer((int) $user->customer_id)->exists()
            || Website::where('customer_id', $user->customer_id)->where('status', '!=', 'removing')->get()
                ->contains(fn (Website $w) => CronManager::allowed($w));
    }

    /** Keep the jobs in step with their website: suspend, resume, remove. */
    private function hooks(): void
    {
        Website::updated(function (Website $website) {
            if (! $website->wasChanged('status')) {
                return;
            }
            $manager = $this->app->make(CronManager::class);
            $status = $website->status;
            if ($status === 'suspended') {
                $manager->suspend($website, true);
            } elseif ($status === 'active' && $website->getOriginal('status') === 'suspended') {
                $manager->suspend($website, false);
            } elseif ($status === 'removing') {
                foreach (CronJob::withoutGlobalScopes()->where('website_id', $website->id)->get() as $job) {
                    try {
                        $manager->remove($job->setRelation('website', $website));
                    } catch (AgentException $e) {
                        Log::warning('cron.remove failed', ['job' => $job->uid, 'error' => $e->getMessage()]);
                    }
                }
            }
        });
    }
}
