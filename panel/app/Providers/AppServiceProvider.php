<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Services\Agent\SocketAgentClient;
use App\Services\MailConfigurator;
use App\Services\Settings;
use App\Support\Menu;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(AgentClient::class, function () {
            return config('panel.agent_driver') === 'fake'
                ? new FakeAgentClient
                : new SocketAgentClient(config('panel.agent_socket'));
        });
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        if (! $this->app->runningUnitTests()) {
            try {
                $this->app->make(MailConfigurator::class)->apply();
            } catch (Throwable) {
                // No database yet (fresh install): keep the default mailer.
            }
        }

        $this->registerMenus();
    }

    private function registerMenus(): void
    {
        Menu::admin()
            ->add('dashboard', 'Dashboard', 'admin.dashboard', icon: 'dashboard', order: 0)
            ->add('customers', 'Customers', 'admin.customers.index', icon: 'customers', section: 'Hosting', order: 10)
            ->add('packages', 'Packages', 'admin.packages.index', icon: 'package', section: 'Hosting', order: 20)
            ->add('websites', 'Websites', 'admin.websites.index', icon: 'globe', section: 'Hosting', order: 30)
            ->add('statistics', 'Statistics', 'admin.statistics.index', icon: 'chart', section: 'Server', order: 90)
            ->add('services', 'Services', 'admin.services.index', icon: 'server', section: 'Server', order: 100)
            ->add('security', 'Security', 'admin.security.index', icon: 'shield', section: 'Server', order: 110)
            ->add('firewall', 'Firewall', 'admin.firewall.index', icon: 'firewall', section: 'Server', order: 120)
            ->add('mail-settings', 'Mail server (SMTP)', 'admin.settings.mail.edit', icon: 'send', section: 'System', order: 200, activePatterns: ['admin.settings.mail.*'])
            ->add('system', 'Updates', 'admin.system.index', icon: 'refresh', section: 'System', order: 210)
            ->add('activity', 'Activity log', 'admin.activity.index', icon: 'list', section: 'System', order: 220);

        Menu::client()
            ->add('overview', 'Overview', 'client.dashboard', icon: 'dashboard', order: 0)
            ->add('websites', 'Websites', 'client.websites.index', icon: 'globe', order: 10);
    }
}
