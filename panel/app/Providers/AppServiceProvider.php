<?php

namespace App\Providers;

use App\Auth\NoTrustedNetworks;
use App\Auth\TrustedNetworks;
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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        // rhis/security binds the real list; bindIf keeps whichever comes first.
        $this->app->bindIf(TrustedNetworks::class, NoTrustedNetworks::class);

        $this->app->singleton(AgentClient::class, function () {
            return config('panel.agent_driver') === 'fake'
                ? new FakeAgentClient
                : new SocketAgentClient(config('panel.agent_socket'));
        });
    }

    public function boot(): void
    {
        // Strings of the panel shell (menu, top bar) live in their own file next to lang/nl.json.
        $this->app['translator']->addJsonPath(lang_path('ui'));

        // The panel runs on its own port (:8443). Build every URL from APP_URL, so
        // asset links and redirects never lose the port, whatever Host the web
        // server passes on.
        if (! $this->app->runningUnitTests() && ($root = config('app.url'))) {
            URL::forceRootUrl($root);
            if (str_starts_with($root, 'https://')) {
                URL::forceScheme('https');
            }
        }

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

        // Translations of panel features live in their own file (lang/features/<feature>/nl.json),
        // so parallel work does not keep changing lang/nl.json.
        foreach (glob(lang_path('features/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $this->loadJsonTranslationsFrom($dir);
        }

        $this->registerMenus();
    }

    private function registerMenus(): void
    {
        Menu::admin()
            ->add('dashboard', 'Dashboard', 'admin.dashboard', icon: 'dashboard', order: 0)
            ->add('quick', 'Quick view', 'admin.quick', icon: 'bolt', order: 5, activePatterns: ['admin.quick*'])
            ->add('customers', 'Customers', 'admin.customers.index', icon: 'customers', section: 'Hosting', order: 10)
            ->add('packages', 'Packages', 'admin.packages.index', icon: 'package', section: 'Hosting', order: 20)
            ->add('websites', 'Websites', 'admin.websites.index', icon: 'globe', section: 'Hosting', order: 30)
            ->add('php', 'PHP settings', 'admin.php.index', icon: 'code', section: 'Server', order: 95)
            ->add('statistics', 'Statistics', 'admin.statistics.index', icon: 'chart', section: 'Server', order: 90)
            ->add('services', 'Services', 'admin.services.index', icon: 'server', section: 'Server', order: 100)
            ->add('security', 'Security', 'admin.security.index', icon: 'shield', section: 'Server', order: 110)
            ->add('firewall', 'Firewall', 'admin.firewall.index', icon: 'firewall', section: 'Server', order: 120)
            ->add('mail-settings', 'Mail server (SMTP)', 'admin.settings.mail.edit', icon: 'send', section: 'System', order: 200, activePatterns: ['admin.settings.mail.*'])
            ->add('system', 'Updates', 'admin.system.index', icon: 'refresh', section: 'System', order: 210)
            ->add('activity', 'Activity log', 'admin.activity.index', icon: 'list', section: 'System', order: 220)
            ->add('tools', 'Tools & settings', 'admin.tools.index', icon: 'tools', section: 'Server management', order: 300);

        Menu::client()
            ->add('overview', 'Overview', 'client.dashboard', icon: 'dashboard', order: 0)
            ->add('websites', 'Websites', 'client.websites.index', icon: 'globe', order: 10);

        $this->arrangeMenus();
    }

    /**
     * One place that decides how the menus are grouped, so packages can keep
     * registering their items as they do. Unknown items keep their own section.
     */
    private function arrangeMenus(): void
    {
        // Admin: like a classic hosting panel. Home, then collapsible groups;
        // settings pages are not in the menu but under "Tools & settings".
        $count = fn (string $model) => fn () => $model::query()->count();
        $tools = ['parent' => 'tools'];
        Menu::admin()->arrange(
            ['', 'Hosting services', 'Additional services', 'Server management'],
            [
                'dashboard' => ['section' => '', 'order' => 0, 'label' => 'Home', 'icon' => 'home'],
                'quick' => ['section' => '', 'order' => 5],
                'customers' => ['section' => 'Hosting services', 'order' => 100, 'badge' => $count(\App\Models\Customer::class)],
                'websites' => ['section' => 'Hosting services', 'order' => 110, 'label' => 'Domains', 'badge' => $count(\App\Models\Website::class)],
                'subscriptions' => ['section' => 'Hosting services', 'order' => 120],
                'packages' => ['section' => 'Hosting services', 'order' => 130, 'label' => 'Service plans', 'badge' => $count(\App\Models\HostingPackage::class)],
                'domains' => ['section' => 'Hosting services', 'order' => 140, 'label' => 'Mail & DNS'],
                'domain-aliases' => ['section' => 'Hosting services', 'order' => 150],
                'migration' => ['section' => 'Hosting services', 'order' => 160, 'label' => 'Migrations'],
                'wordpress' => ['section' => 'Additional services', 'order' => 200],
                'apps' => ['section' => 'Additional services', 'order' => 210, 'label' => 'Apps'],
                'git' => ['section' => 'Additional services', 'order' => 220],
                'databases' => ['section' => 'Additional services', 'order' => 230],
                'files' => ['section' => 'Additional services', 'order' => 240],
                'tools' => ['section' => 'Server management', 'order' => 300],
                'security-overview' => ['section' => 'Server management', 'order' => 310, 'label' => 'Security center'],
                'statistics' => ['section' => 'Server management', 'order' => 320],
                'services' => ['section' => 'Server management', 'order' => 330, 'label' => 'Monitoring'],
                'backups' => ['section' => 'Server management', 'order' => 340],
                // Reached through Tools & settings; the menu marks that item active.
                'security' => $tools + ['label' => 'Attack blocking'],
                'protection' => $tools + ['label' => 'Login protection'],
                'firewall' => $tools,
                'php' => $tools + ['label' => 'PHP settings'],
                'mail-settings' => $tools,
                'dns-mail-settings' => $tools,
                'mail-log' => $tools,
                'crm-api' => $tools,
                'system' => $tools + ['label' => 'Updates & release channel'],
                'activity' => $tools + ['label' => 'Activity log'],
                'server-management' => $tools,
            ],
        );

        Menu::client()->arrange(
            ['', 'Overview', 'Websites', 'Email & domains', 'Files & data', 'Account'],
            [
                'overview' => ['section' => 'Overview', 'order' => 0, 'label' => 'My hosting'],
                'websites' => ['section' => 'Websites', 'order' => 100],
                'wordpress' => ['section' => 'Websites', 'order' => 110],
                'apps' => ['section' => 'Websites', 'order' => 120],
                'git' => ['section' => 'Websites', 'order' => 125],
                'security-alerts' => ['section' => 'Websites', 'order' => 130, 'label' => 'Security'],
                'protection' => ['section' => 'Websites', 'order' => 135, 'label' => 'Login protection'],
                'mail' => ['section' => 'Email & domains', 'order' => 200],
                'domain-aliases' => ['section' => 'Email & domains', 'order' => 210],
                'files' => ['section' => 'Files & data', 'order' => 290],
                'backups' => ['section' => 'Files & data', 'order' => 300],
                'shop' => ['section' => 'Account', 'order' => 400],
            ],
        );
    }
}
