<?php

declare(strict_types=1);

namespace Rhis\Backups;

use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Rhis\Backups\Console\ClientBackupTick;
use Rhis\Backups\Console\RestoreTestTick;
use Rhis\Backups\Models\RestoreTest;
use Illuminate\Support\Facades\Schema;

final class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backups.php', 'backups');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backups');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/backups')->name('admin.backups.')
            ->group(__DIR__.'/../routes/admin.php');
        // The upload screen's script (the panel's CSP allows no inline scripts).
        Route::get('/backups-assets/upload.js', fn () => response()->file(__DIR__.'/../resources/js/upload.js', [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=86400',
        ]))->name('backups.assets.upload');
        Route::middleware(['web', 'auth', 'client'])->prefix('backups')->name('client.backups.')
            ->group(__DIR__.'/../routes/client.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ClientBackupTick::class, RestoreTestTick::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('backups:client-tick')->everyFiveMinutes()->withoutOverlapping(30);
            $schedule->command('backups:restore-test-tick')->everyFiveMinutes()->withoutOverlapping(30);
        });
        $this->fakeAgentDefaults();

        Menu::admin()->add('backups', 'Backups', 'admin.backups.index', icon: 'backup', section: 'Server', order: 85,
            activePatterns: ['admin.backups.*'], badge: self::restoreTestBadge(...));
        Menu::client()->add('backups', 'Backups', 'client.backups.index', icon: 'backup', order: 40,
            activePatterns: ['client.backups.*'],
            visible: fn () => (bool) (config('backups.customer_restore') || config('backups.customer_targets')));
    }

    /** "!" on the Backups menu item while the newest restore test failed. */
    public static function restoreTestBadge(): ?string
    {
        if (! Schema::hasTable('backup_restore_tests')) {
            return null;
        }
        $last = RestoreTest::whereIn('status', RestoreTest::FINISHED)->latest('id')->first();

        return $last?->isProblem() ? '!' : null;
    }

    /** Answers of the development/test agent for the customer backup actions (kept here, next to the feature). */
    private function fakeAgentDefaults(): void
    {
        $this->callAfterResolving(AgentClient::class, static function ($agent): void {
            if (! $agent instanceof FakeAgentClient) {
                return;
            }
            $job = fn () => ['job' => (string) Str::uuid()];
            $agent->respond('client_backup.target_test', fn (array $p) => [
                'reachable' => true, 'writable' => true, 'duration_ms' => 87, 'backups' => 0,
                'host_key' => in_array($p['target']['type'] ?? '', ['sftp', 'scp'], true) ? ($p['target']['host_key'] ?? ($p['target']['host'] ?? 'host').' ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeHostKey') : null,
                'fingerprints' => in_array($p['target']['type'] ?? '', ['sftp', 'scp'], true) ? ['ssh-ed25519 SHA256:fakefingerprint'] : [],
            ]);
            $agent->respond('client_backup.list', fn (array $p) => ['backups' => collect(range(0, 4))->map(function ($d) use ($p) {
                $stamp = now('UTC')->subDays($d)->setTime(3, 0)->format('Ymd-His');
                $base = now('UTC')->subDays(4)->setTime(3, 0)->format('Ymd-His');
                $full = $d === 4;

                return ['file' => ($p['domain'] ?? 'example.test').'-'.$stamp.($full ? '-full.zip' : '-incr-'.$base.'.zip'),
                    'kind' => $full ? 'full' : 'incremental', 'stamp' => $stamp, 'created' => now('UTC')->subDays($d)->setTime(3, 0)->toIso8601String(),
                    'base' => $full ? null : ($p['domain'] ?? 'example.test').'-'.$base.'-full.zip', 'size' => $full ? 48_000_000 : 1_200_000 + $d];
            })->all()]);
            $agent->respond('client_backup.run', $job);
            $agent->respond('client_backup.restore', $job);
            $agent->respond('client_backup.restore_upload', $job);
            $agent->respond('client_backup.delete', fn (array $p) => ['deleted' => $p['file'] ?? '']);
            $agent->respond('client_backup.discard_upload', ['discarded' => true]);
            $agent->respond('client_backup.inspect_upload', [
                'domain' => 'example.test', 'kind' => 'full', 'created' => now('UTC')->subDay()->toIso8601String(), 'base' => null,
                'size' => 52_000_000, 'contains' => ['databases', 'files', 'mail'], 'database_files' => ['example_wp_1a2b'],
            ]);
            $agent->respond('backup.restore_many', $job);
            $agent->respond('backup.verify', $job);
            $agent->respond('backup.verify_last', ['report' => null]);
            // Without a scope: restore points of everything on this (development) panel, for a week.
            $agent->respond('backup.list', function (array $p) {
                $scopes = isset($p['scope']) ? [$p['scope']] : array_merge(['system'],
                    ...Website::orderBy('domain')->pluck('domain')->map(fn ($d) => ["site:{$d}", "mail:{$d}"])->all(),
                    ...[SiteDatabase::orderBy('name')->pluck('name')->map(fn ($n) => "db:{$n}")->all()]);
                $n = 0;
                $out = [];
                foreach (range(0, 6) as $d) {
                    foreach ($scopes as $i => $scope) {
                        $id = dechex(0xA1B2C300 + $n++);
                        $out[] = ['id' => str_pad($id, 64, '0'), 'short_id' => $id, 'time' => now()->subDays($d)->setTime(2, 30 + $i % 25)->toIso8601String(),
                            'host' => 'web01.example.test', 'paths' => [], 'kind' => $d % 7 === 3 ? 'full' : 'incremental', 'scope' => $scope, 'size' => null];
                    }
                }

                return ['snapshots' => $out];
            });
        });
    }
}
