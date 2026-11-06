<?php

declare(strict_types=1);

namespace Rhis\Apps;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;
use Rhis\Apps\Models\AppInstallation;

/** Starts installs and removals on the agent and keeps the panel's records in step with its jobs. */
final class AppInstaller
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Every app that both the panel and this server's agent know, with the agent's
     * release information: latest_version, released_at (epoch) and maintained
     * (false = not offered, no release in max_release_age_days or end of life).
     */
    public function catalog(): array
    {
        $maxAge = $this->maxAge();
        $agent = Cache::remember('apps.agent-catalog.'.$maxAge, 600, function () use ($maxAge) {
            try {
                return collect($this->agent->call('apps.catalog', ['max_age_days' => $maxAge], 60)['apps'] ?? [])
                    ->keyBy('id')->all();
            } catch (AgentException) {
                return null;
            }
        });
        $catalog = config('apps.catalog', []);
        if (! is_array($agent)) {
            return $catalog;
        }
        $out = [];
        foreach ($catalog as $id => $meta) {
            if (! isset($agent[$id])) {
                continue;
            }
            $a = $agent[$id];
            $out[$id] = $meta + [
                'latest_version' => $a['latest_version'] ?? null,
                'released_at' => isset($a['released_at']) ? (int) $a['released_at'] : null,
                'maintained' => $a['maintained'] ?? null,
                'end_of_life' => (bool) ($a['end_of_life'] ?? false),
                'admin_user_pattern' => $a['admin_user_pattern'] ?? null,
            ];
        }

        return $out;
    }

    /** The apps that can be installed now (maintained ones). */
    public function offered(): array
    {
        return array_filter($this->catalog(), fn (array $app) => ($app['maintained'] ?? null) !== false);
    }

    public function app(string $id): ?array
    {
        return $this->offered()[$id] ?? null;
    }

    public function maxAge(): int
    {
        return max(30, min(3650, (int) config('apps.max_release_age_days', 365)));
    }

    /**
     * The version on disk and whether a newer release is out, per agent install id
     * (apps update themselves, so the version recorded at install time gets old).
     *
     * @return array<string, array{version: ?string, latest_version: ?string, update_available: bool}>
     */
    public function versions(): array
    {
        try {
            $rows = $this->agent->call('apps.list', [], 30)['apps'] ?? [];
        } catch (AgentException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $out[$row['id']] = [
                    'version' => $row['version'] ?? null,
                    'latest_version' => $row['latest_version'] ?? null,
                    'update_available' => (bool) ($row['update_available'] ?? false),
                ];
            }
        }

        return $out;
    }

    /** Keep the recorded versions in step with the server and return the update info per installation id. */
    public function refreshVersions(iterable $installations): array
    {
        $versions = null;
        $info = [];
        foreach ($installations as $installation) {
            if ($installation->status !== 'installed' || ! $installation->agent_id) {
                continue;
            }
            $versions ??= $this->versions();
            $row = $versions[$installation->agent_id] ?? null;
            if ($row === null) {
                continue;
            }
            if ($row['version'] && $row['version'] !== $installation->version) {
                $installation->update(['version' => $row['version']]);
            }
            $info[$installation->id] = $row;
        }

        return $info;
    }

    /**
     * How many more apps with a database the packages of the customer's websites allow
     * (null = no limit on at least one website). The limit itself is per website: see databasesLeftFor().
     */
    public function databasesLeft(Customer $customer): ?int
    {
        $left = 0;
        foreach ($customer->websites()->with('subscription.package')->get() as $website) {
            $site = $this->databasesLeftFor($website);
            if ($site === null) {
                return null;
            }
            $left += $site;
        }

        return $left;
    }

    /** How many more databases the package of this website's subscription allows (null = no limit). */
    public function databasesLeftFor(Website $website): ?int
    {
        $max = $website->package?->max_databases;
        if ($max === null) {
            return null;
        }
        // Every database of the website (apps, migrated, ...) plus installs still running.
        $used = SiteDatabase::where('website_id', $website->id)->count()
            + AppInstallation::where('website_id', $website->id)->where('status', 'installing')->count();

        return max(0, $max - $used);
    }

    /**
     * @param  array{path: string, title: string, admin_user: string, admin_email: string, admin_password: string, locale: string}  $data
     */
    public function install(Website $website, string $app, array $data): AppInstallation
    {
        $job = $this->agent->call('apps.install', [
            'domain' => $website->domain, 'app' => $app, 'path' => $data['path'],
            'title' => $data['title'], 'admin_user' => $data['admin_user'], 'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'], 'locale' => $data['locale'],
            'max_age_days' => $this->maxAge(),
        ], 90);

        $installation = AppInstallation::create([
            'website_id' => $website->id, 'customer_id' => $website->customer_id, 'app' => $app,
            'path' => $data['path'], 'status' => 'installing', 'job_id' => $job['job'],
            'admin_user' => $data['admin_user'], 'locale' => $data['locale'],
        ]);
        ActivityLog::record('app.install', $installation->location(), ['app' => $app], $website->customer_id);

        return $installation;
    }

    public function remove(AppInstallation $installation): void
    {
        if ($installation->status === 'failed' || $installation->agent_id === null) {
            // Nothing on the server: a failed install is rolled back by the agent.
            $installation->delete();

            return;
        }
        $job = $this->agent->call('apps.remove', ['domain' => $installation->website->domain, 'id' => $installation->agent_id], 60);
        $installation->update(['status' => 'removing', 'job_id' => $job['job'], 'error' => null]);
        ActivityLog::record('app.remove', $installation->location(), ['app' => $installation->app], $installation->customer_id);
    }

    /** Follow the agent job of a busy installation. Returns the job state (null when unknown). */
    public function sync(AppInstallation $installation): ?array
    {
        if (! $installation->busy() || ! $installation->job_id) {
            return null;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $installation->job_id], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                // The agent lost the job (for example after a reinstall of the server).
                $installation->update(['status' => $installation->status === 'removing' ? 'installed' : 'failed', 'error' => $e->getMessage()]);
            }

            return null;
        }
        if (($job['status'] ?? 'running') === 'running') {
            return $job;
        }
        $ok = $job['status'] === 'succeeded';
        $result = $job['result'] ?? [];
        $error = $job['error']['message'] ?? null;

        if ($installation->status === 'removing') {
            if ($ok) {
                if ($installation->database) {
                    SiteDatabase::where('name', $installation->database)->delete();
                }
                $installation->delete();
            } else {
                $installation->update(['status' => 'installed', 'error' => $error]);
            }

            return $job;
        }
        if ($ok) {
            $installation->update([
                'status' => 'installed', 'agent_id' => $result['id'] ?? null, 'version' => $result['version'] ?? null,
                'url' => $result['url'] ?? null, 'admin_url' => $result['admin_url'] ?? null,
                'database' => $result['database'] ?? null, 'installed_at' => now(), 'error' => null,
            ]);
            if (! empty($result['database'])) {
                SiteDatabase::updateOrCreate(['name' => $result['database']], [
                    'website_id' => $installation->website_id, 'users' => [$result['database']], 'origin' => 'app',
                ]);
            }
            if (! empty($result['page_cache_disabled'])) {
                $installation->website->update(['page_cache' => false]);
            }
        } else {
            $installation->update(['status' => 'failed', 'error' => $error ?? __('The installation failed.')]);
        }

        return $job;
    }

    /** Sync every busy installation in a list (index pages). */
    public function syncAll(iterable $installations): void
    {
        foreach ($installations as $installation) {
            $this->sync($installation);
        }
    }
}
