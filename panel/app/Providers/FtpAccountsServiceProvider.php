<?php

namespace App\Providers;

use App\Console\Commands\SyncFtpAccounts;
use App\Models\ActivityLog;
use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Services\FtpAccounts;
use App\Services\Team\WebsiteScope;
use App\Support\Menu;
use App\Support\TeamAreas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Extra FTP/SFTP accounts per website (docs/ftp-accounts.md). */
class FtpAccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('ftp-accounts.php'), 'ftp-accounts');
        $this->app->singleton(FtpAccounts::class);
    }

    public function boot(): void
    {
        FtpAccount::addGlobalScope(new WebsiteScope);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncFtpAccounts::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Retry soon after a change the server did not get, and keep the server in line once an hour.
            $schedule->command('whp:ftp:sync --pending')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('whp:ftp:sync')->hourly()->withoutOverlapping();
        });

        if (! $this->app->routesAreCached()) {
            // The admin routes are routes/admin-ftp-accounts.php (joined by bootstrap/app.php).
            Route::middleware(['web', 'auth', 'client'])->name('client.')->group(base_path('routes/client-ftp-accounts.php'));
        }

        Menu::admin()->add('ftp-accounts', 'FTP accounts', 'admin.ftp-accounts.index', icon: 'key', section: 'Hosting services', order: 155);
        Menu::client()->add('ftp-accounts', 'FTP accounts', 'client.ftp-accounts.index', icon: 'key', section: 'Files & data', order: 295,
            visible: fn () => (bool) config('ftp-accounts.customer_manage') || FtpAccount::query()->exists());
        TeamAreas::extend('files', ['client.ftp-accounts.*']);

        ActivityLog::labels([
            'ftp_account.created' => 'FTP account created',
            'ftp_account.updated' => 'FTP account changed',
            'ftp_account.deleted' => 'FTP account removed',
        ]);

        // A suspended, removed or renamed website: its accounts follow at once.
        Website::updated(function (Website $website) {
            if ($website->wasChanged(['status', 'domain']) && FtpAccount::withoutGlobalScopes()->where('website_id', $website->id)->exists()) {
                $this->app->make(FtpAccounts::class)->syncOrWarn();
            }
        });
        Website::deleting(function (Website $website) {
            if (FtpAccount::withoutGlobalScopes()->where('website_id', $website->id)->exists()) {
                Cache::put('ftp-accounts.removed-site.'.$website->id, true, now()->addMinutes(10));
            }
        });
        Website::deleted(function (Website $website) {
            if (Cache::pull('ftp-accounts.removed-site.'.$website->id)) {
                FtpAccount::withoutGlobalScopes()->where('website_id', $website->id)->delete();
                $this->app->make(FtpAccounts::class)->syncOrWarn();
            }
        });

        // Local development and tests run without the real agent.
        $fake = function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                $agent->respond('ftp.sync', fn (array $p) => ['accounts' => count($p['accounts'] ?? []),
                    'read_only' => collect($p['accounts'] ?? [])->where('read_only', true)->count(), 'skipped' => []]);
                $agent->respond('ftp.status', ['installed' => true, 'active' => true, 'ftp_port' => 21, 'sftp_port' => 2222,
                    'passive_ports' => [49152, 49407], 'tls_required' => true, 'accounts' => 0,
                    'fingerprints' => [['type' => 'ED25519', 'sha256' => 'SHA256:Jq3r1vV0m3kQ8yQx0x7zv2bT7ZpN1oVd9eY3cS2wHkA']]]);
            }
        };
        if ($this->app->resolved(AgentClient::class)) {
            $fake($this->app->make(AgentClient::class));
        } else {
            $this->callAfterResolving(AgentClient::class, $fake);
        }
    }
}
