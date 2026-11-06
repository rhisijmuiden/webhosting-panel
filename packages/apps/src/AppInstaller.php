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

    /** Catalog entries that both the panel and this server's agent know. */
    public function catalog(): array
    {
        $known = Cache::remember('apps.agent-catalog', 600, function () {
            try {
                return collect($this->agent->call('apps.catalog')['apps'] ?? [])->pluck('id')->all();
            } catch (AgentException) {
                return null;
            }
        });
        $catalog = config('apps.catalog', []);
        if (is_array($known)) {
            $catalog = array_intersect_key($catalog, array_flip($known));
        }

        return $catalog;
    }

    public function app(string $id): ?array
    {
        return $this->catalog()[$id] ?? null;
    }

    /** How many more apps with a database the customer's package allows (null = no limit). */
    public function databasesLeft(Customer $customer): ?int
    {
        $max = $customer->package?->max_databases;
        if ($max === null) {
            return null;
        }
        // Every database of the customer's websites (apps, migrated, ...) plus installs still running.
        $used = SiteDatabase::whereIn('website_id', $customer->websites()->select('id'))->count()
            + AppInstallation::where('customer_id', $customer->id)->where('status', 'installing')->count();

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
        ], 60);

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
