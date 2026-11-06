<?php

declare(strict_types=1);

namespace Rhis\WebApps;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rhis\WebApps\Models\WebApp;

/** Everything the panel asks the agent about Node.js and Python apps and runtimes. */
final class WebAppManager
{
    public function __construct(private readonly AgentClient $agent) {}

    /** Node.js and Python versions on the server (cached a minute). */
    public function runtimes(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('webapps.runtimes');
        }

        return Cache::remember('webapps.runtimes', 60, function () {
            try {
                return $this->agent->call('runtime.list', [], 30);
            } catch (AgentException $e) {
                return ['node' => [], 'python' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /** Versions a new app may use: installed and not end of life. [runtime => [version => label]] */
    public function choices(?WebApp $keep = null): array
    {
        $out = [];
        foreach (array_keys(WebApp::RUNTIMES) as $runtime) {
            foreach ($this->runtimes()[$runtime] ?? [] as $row) {
                $current = $keep && $keep->runtime === $runtime && $keep->runtime_version === $row['version'];
                if (($row['installed'] ?? false) && (! ($row['eol'] ?? false) || $current)) {
                    $out[$runtime][$row['version']] = trim(($row['full_version'] ?? $row['version']).(($row['lts'] ?? null) ? ' LTS' : ''));
                }
            }
        }

        return $out;
    }

    /** How many apps this website may have (null = no package limit). */
    public static function limit(Website $website): ?int
    {
        $max = $website->package?->max_webapps;
        $cap = (int) config('webapps.max_per_website', 10);

        return $max === null ? $cap : min($max, $cap);
    }

    public static function allowed(Website $website): bool
    {
        return self::limit($website) > 0;
    }

    public function payload(WebApp $app): array
    {
        $package = $app->website->package;

        return [
            'id' => $app->uid, 'domain' => $app->website->domain, 'name' => $app->name,
            'runtime' => $app->runtime, 'version' => $app->runtime_version, 'root' => $app->app_root,
            'mount' => $app->mount_path, 'strip_prefix' => $app->strip_prefix,
            'start' => $app->start_command, 'build' => $app->build_command,
            'env' => (object) ($app->env ?? []), 'enabled' => $app->enabled && $app->website->status !== 'suspended',
            'deps_on_deploy' => $app->deps_on_deploy,
            'memory_mb' => $package?->webapp_memory_mb, 'cpu_percent' => $package?->webapp_cpu_percent,
        ];
    }

    /** Write the app to the server and (re)start it. Throws AgentException. */
    public function apply(WebApp $app): array
    {
        $app->loadMissing('website');
        try {
            $out = $this->agent->call('webapp.apply', $this->payload($app), 240);
        } catch (AgentException $e) {
            $app->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
        $app->update(['port' => $out['port'] ?? $app->port, 'last_state' => $out['status']['state'] ?? null, 'last_error' => null]);

        return $out;
    }

    /** Apply again without failing the caller (package change, unsuspend). */
    public function reapply(WebApp $app): void
    {
        try {
            $this->apply($app);
        } catch (AgentException $e) {
            Log::warning('webapp.apply failed', ['app' => $app->uid, 'error' => $e->getMessage()]);
        }
    }

    public function status(WebApp $app): ?array
    {
        try {
            $st = $this->agent->call('webapp.status', ['id' => $app->uid], 20);
        } catch (AgentException) {
            return null;
        }
        if (($st['state'] ?? null) !== $app->last_state) {
            $app->update(['last_state' => $st['state'] ?? null]);
        }

        return $st;
    }

    /** Live status of many apps: [uid => status]. */
    public function statuses(Collection $apps): array
    {
        if ($apps->isEmpty()) {
            return [];
        }
        try {
            return $this->agent->call('webapp.list', ['ids' => $apps->pluck('uid')->values()->all()], 30)['apps'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }

    /** start / stop are kept after a reboot (the unit is enabled or disabled); restart only restarts. */
    public function control(WebApp $app, string $op): array
    {
        if ($op === 'restart') {
            $out = $this->agent->call('webapp.control', ['id' => $app->uid, 'op' => 'restart'], 120);
            $app->update(['last_state' => $out['state'] ?? null]);

            return $out;
        }
        $app->enabled = $op === 'start';
        $app->save();

        return $this->apply($app)['status'] ?? [];
    }

    public function logs(WebApp $app, int $lines = 200): array
    {
        return $this->agent->call('webapp.logs', ['id' => $app->uid, 'lines' => $lines], 30)['lines'] ?? [];
    }

    public function install(WebApp $app): string
    {
        $job = $this->agent->call('webapp.install', ['id' => $app->uid], 30)['job'];
        $app->update(['install_job' => $job]);

        return $job;
    }

    public function job(string $job): array
    {
        return $this->agent->call('job.status', ['job' => $job], 10);
    }

    public function remove(WebApp $app, bool $removeDeps = false): void
    {
        $this->agent->call('webapp.remove', ['id' => $app->uid, 'remove_deps' => $removeDeps], 240);
        $app->delete();
    }

    public function suspend(Website $website, bool $suspended): void
    {
        if (! WebApp::where('website_id', $website->id)->exists()) {
            return;
        }
        try {
            $this->agent->call('webapp.suspend', ['domain' => $website->domain, 'suspended' => $suspended], 180);
        } catch (AgentException $e) {
            Log::warning('webapp.suspend failed', ['domain' => $website->domain, 'error' => $e->getMessage()]);
        }
    }
}
