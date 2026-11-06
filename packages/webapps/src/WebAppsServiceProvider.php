<?php

declare(strict_types=1);

namespace Rhis\WebApps;

use App\Models\Subscription;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\WebApps\Models\WebApp;

final class WebAppsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webapps.php', 'webapps');
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeWebApps::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webapps');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::bind('webapp', fn ($value) => WebApp::with('website')->findOrFail((int) $value));
        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/webapps')->name('admin.webapps.')
            ->group(__DIR__.'/../routes/admin.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('webapps')->name('client.webapps.')
            ->group(__DIR__.'/../routes/client.php');

        Menu::admin()->add('webapps', 'Node.js & Python', 'admin.webapps.index', icon: 'runtime', section: 'Hosting', order: 36,
            activePatterns: ['admin.webapps.*']);
        Menu::client()->add('webapps', 'Node.js & Python', 'client.webapps.index', icon: 'runtime', order: 18,
            activePatterns: ['client.webapps.*'], visible: fn () => self::clientVisible());

        // Team members with access to Git deployments may also manage the apps.
        if (class_exists(\App\Support\TeamAreas::class)) {
            \App\Support\TeamAreas::extend('git', ['client.webapps.*']);
        }

        $this->hooks();
    }

    /** Customers see the menu item when they have an app, or a website whose package allows one. */
    private static function clientVisible(): bool
    {
        $user = auth()->user();
        if (! config('webapps.customer_access') || ! $user?->customer_id) {
            return false;
        }

        return WebApp::forCustomer((int) $user->customer_id)->exists()
            || Website::where('customer_id', $user->customer_id)->where('status', '!=', 'removing')->get()
                ->contains(fn (Website $w) => WebAppManager::allowed($w));
    }

    /** Keep the apps in step with their website: suspend, remove, package change. */
    private function hooks(): void
    {
        Website::updated(function (Website $website) {
            if (! $website->wasChanged('status')) {
                return;
            }
            $manager = $this->app->make(WebAppManager::class);
            $status = $website->status;
            if ($status === 'suspended') {
                $manager->suspend($website, true);
            } elseif ($status === 'active' && $website->getOriginal('status') === 'suspended') {
                $manager->suspend($website, false);
            } elseif ($status === 'removing') {
                foreach (WebApp::where('website_id', $website->id)->get() as $app) {
                    try {
                        $manager->remove($app->setRelation('website', $website));
                    } catch (AgentException $e) {
                        Log::warning('webapp.remove failed', ['app' => $app->uid, 'error' => $e->getMessage()]);
                    }
                }
            }
        });

        // Another package: new memory and CPU caps for the apps of that website (and its subdomains).
        Subscription::updated(function (Subscription $subscription) {
            if (! $subscription->wasChanged('hosting_package_id')) {
                return;
            }
            $ids = Website::where('id', $subscription->website_id)->orWhere('parent_id', $subscription->website_id)->pluck('id');
            $manager = $this->app->make(WebAppManager::class);
            foreach (WebApp::with('website')->whereIn('website_id', $ids)->get() as $app) {
                $manager->reapply($app);
            }
        });
    }
}
