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
            ->add('customers', 'Customers', 'admin.customers.index', icon: 'customers', section: 'Hosting', order: 10)
            ->add('packages', 'Packages', 'admin.packages.index', icon: 'package', section: 'Hosting', order: 20)
            ->add('subscriptions', 'Subscriptions', 'admin.subscriptions.index', icon: 'apps', section: 'Hosting', order: 25, activePatterns: ['admin.subscriptions.*'])
            ->add('websites', 'Websites', 'admin.websites.index', icon: 'globe', section: 'Hosting', order: 30)
            ->add('php', 'PHP settings', 'admin.php.index', icon: 'code', section: 'Server', order: 95)
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

        $this->arrangeMenus();
    }

    /**
     * One place that decides how the menus are grouped, so packages can keep
     * registering their items as they do. Unknown items keep their own section.
     */
    private function arrangeMenus(): void
    {
        Menu::admin()->arrange(
            ['', 'Overview', 'Customers', 'Hosting', 'Domains & mail', 'Server', 'System'],
            [
                'dashboard' => ['section' => 'Overview', 'order' => 0],
                'security-overview' => ['section' => 'Overview', 'order' => 10, 'label' => 'Security center'],
                'activity' => ['section' => 'Overview', 'order' => 20, 'label' => 'Activity'],
                'customers' => ['section' => 'Customers', 'order' => 100],
                'packages' => ['section' => 'Customers', 'order' => 110],
                'migration' => ['section' => 'Customers', 'order' => 120, 'label' => 'Migrations'],
                'websites' => ['section' => 'Hosting', 'order' => 200],
                'wordpress' => ['section' => 'Hosting', 'order' => 210],
                'apps' => ['section' => 'Hosting', 'order' => 220, 'label' => 'Apps'],
                'git' => ['section' => 'Hosting', 'order' => 230],
                'databases' => ['section' => 'Hosting', 'order' => 240],
                'files' => ['section' => 'Hosting', 'order' => 250],
                'domains' => ['section' => 'Domains & mail', 'order' => 300, 'label' => 'Domains & DNS'],
                'domain-aliases' => ['section' => 'Domains & mail', 'order' => 310],
                'mail-log' => ['section' => 'Domains & mail', 'order' => 320],
                'services' => ['section' => 'Server', 'order' => 400],
                'security' => ['section' => 'Server', 'order' => 405, 'label' => 'Attack blocking'],
                'protection' => ['section' => 'Server', 'order' => 407, 'label' => 'Login protection'],
                'firewall' => ['section' => 'Server', 'order' => 410],
                'backups' => ['section' => 'Server', 'order' => 420],
                'statistics' => ['section' => 'Server', 'order' => 430],
                'php' => ['section' => 'Server', 'order' => 440, 'label' => 'PHP settings'],
                'mail-settings' => ['section' => 'System', 'order' => 500],
                'dns-mail-settings' => ['section' => 'System', 'order' => 510],
                'crm-api' => ['section' => 'System', 'order' => 520],
                'system' => ['section' => 'System', 'order' => 530],
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
