<?php

declare(strict_types=1);

namespace Rhis\ObjectCache;

use App\Models\ActivityLog;
use App\Models\HostingPackage;
use App\Models\Subscription;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rhis\ObjectCache\Models\ObjectCache;

final class ObjectCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/object-cache.php', 'object-cache');
        $this->app->singleton(ObjectCacheManager::class);
        $this->app->afterResolving(AgentClient::class, function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeObjectCache::register($agent);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'object-cache');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/object-cache')->name('admin.object-cache.')
                ->group(__DIR__.'/../routes/admin.php');
            Route::middleware(['web', 'auth', 'client'])->prefix('object-cache')->name('client.object-cache.')
                ->group(__DIR__.'/../routes/client.php');
        }

        Menu::admin()->add('object-cache', 'Object cache', 'admin.object-cache.index', icon: 'bolt', section: 'Additional services', order: 228,
            activePatterns: ['admin.object-cache.*']);

        // Part of the website settings a team member can be given.
        if (class_exists(\App\Support\TeamAreas::class)) {
            \App\Support\TeamAreas::extend('websites', ['client.object-cache.*']);
        }

        ActivityLog::labels([
            'object_cache.enabled' => 'Object cache switched on',
            'object_cache.disabled' => 'Object cache switched off',
            'object_cache.flushed' => 'Object cache emptied',
        ]);

        $this->hooks();
    }

    /** Keep the cache in step with its website and package: suspend, remove, other memory limit. */
    private function hooks(): void
    {
        Website::updated(function (Website $website) {
            if (! $website->wasChanged('status')) {
                return;
            }
            $manager = $this->app->make(ObjectCacheManager::class);
            $status = $website->status;
            if ($status === 'suspended') {
                $manager->suspend($website, true);
            } elseif ($status === 'active' && $website->getOriginal('status') === 'suspended') {
                $manager->suspend($website, false);
            } elseif ($status === 'removing') {
                $manager->remove($website);
            }
        });

        // Another package for a subscription: new memory limit for its website and subdomains.
        Subscription::updated(function (Subscription $subscription) {
            if (! $subscription->wasChanged('hosting_package_id')) {
                return;
            }
            $manager = $this->app->make(ObjectCacheManager::class);
            Website::where('id', $subscription->website_id)->orWhere('parent_id', $subscription->website_id)->get()
                ->each(fn (Website $w) => $manager->reapply($w));
        });

        // The package itself got another memory limit.
        HostingPackage::updated(function (HostingPackage $package) {
            if (! $package->wasChanged('object_cache_mb')) {
                return;
            }
            $manager = $this->app->make(ObjectCacheManager::class);
            $ids = Subscription::where('hosting_package_id', $package->id)->pluck('website_id');
            ObjectCache::with('website')->where('enabled', true)
                ->whereHas('website', fn ($q) => $q->whereIn('id', $ids)->orWhereIn('parent_id', $ids))->get()
                ->each(fn (ObjectCache $c) => $manager->reapply($c->website));
        });
    }
}
