<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Rhis\Subdomains\CloneManager;
use Rhis\Subdomains\Models\SiteClone;
use Rhis\Subdomains\Staging\Models\Staging;
use Rhis\Subdomains\Staging\Models\StagingPush;
use Rhis\Subdomains\SubdomainException;
use Rhis\Subdomains\SubdomainManager;

/**
 * WordPress staging: a copy of a live website on staging.<domain> (made with
 * the clone tool), marked as staging on the server (no outgoing mail, hidden
 * from search engines), and pushed back to live in one go by the agent's
 * wp.staging_push job: backup of live first, files and database, addresses
 * changed back, automatic restore when the push fails.
 */
final class StagingManager
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly CloneManager $clones,
        private readonly SubdomainManager $subdomains,
    ) {}

    /** Stagings this website is the live site or the staging copy of. */
    public function forWebsite(Website $website): Collection
    {
        return Staging::where('live_website_id', $website->id)->orWhere('staging_website_id', $website->id)->latest('id')->get();
    }

    /** The staging this website is the copy of, if it is one. */
    public function stagingOf(Website $website): ?Staging
    {
        return Staging::where('staging_website_id', $website->id)->latest('id')->first();
    }

    /** The WordPress installs of a website (paths), '' when the toolkit does not know it yet. */
    public function paths(Website $website): array
    {
        $paths = class_exists(\Rhis\WordPress\Models\WordPressInstall::class)
            ? \Rhis\WordPress\Models\WordPressInstall::where('website_id', $website->id)->orderBy('path')->pluck('path')->all() : [];

        return $paths ?: [''];
    }

    /** Why this website cannot get a staging copy now, or null. */
    public function blocker(Website $live): ?string
    {
        if ($live->status !== 'active') {
            return __('The website is not active.');
        }
        if ($this->stagingOf($live)) {
            return __('This website is a staging copy itself.');
        }
        $main = $live->isSubdomain() ? $live->parent : $live;

        return $main ? $this->subdomains->blocker($main) : __('The website is not active.');
    }

    public function start(Website $live, string $label, string $path = '', ?int $userId = null): Staging
    {
        if ($error = $this->blocker($live)) {
            throw new SubdomainException($error);
        }
        if (! in_array($path, $this->paths($live), true)) {
            throw new SubdomainException(__('Choose one of the WordPress installs of this website.'));
        }
        $main = $live->isSubdomain() ? $live->parent : $live;
        if ($error = $this->subdomains->invalid($main, $label)) {
            throw new SubdomainException($error);
        }
        $clone = $this->clones->start($live, ['new_label' => $label, 'noindex' => true, 'user_id' => $userId]);
        $staging = Staging::create([
            'customer_id' => $live->customer_id, 'live_website_id' => $live->id, 'staging_website_id' => $clone->target_website_id,
            'live_domain' => $live->domain, 'staging_domain' => $clone->target_domain, 'path' => $path,
            'clone_id' => $clone->id, 'status' => 'copying', 'user_id' => $userId,
        ]);
        ActivityLog::record('wordpress.staging_created', $live->domain, ['staging' => $staging->staging_domain], $live->customer_id);
        $this->sync($staging);

        return $staging->fresh() ?? $staging;
    }

    /** Copy the live website over the staging copy again (start fresh). */
    public function refresh(Staging $staging, ?int $userId = null): Staging
    {
        $this->assertIdle($staging, false);
        [$live, $copy] = $this->sites($staging);
        $clone = $this->clones->start($live, ['target' => $copy, 'replace' => true, 'noindex' => true, 'user_id' => $userId]);
        $staging->update(['clone_id' => $clone->id, 'status' => 'copying', 'error' => null]);
        ActivityLog::record('wordpress.staging_refreshed', $live->domain, ['staging' => $staging->staging_domain], $live->customer_id);
        $this->sync($staging);

        return $staging->fresh() ?? $staging;
    }

    /** Follow the copy (clone job) and the running push; mark the copy as staging once it is there. */
    public function sync(Staging $staging): void
    {
        if ($staging->status === 'copying') {
            $clone = $staging->siteClone;
            if ($clone === null) {
                $staging->update(['status' => 'failed', 'error' => __('The copy was not made.')]);
            } else {
                if ($clone->busy()) {
                    $this->clones->sync($clone);
                    $clone->refresh();
                }
                if ($clone->status === 'succeeded') {
                    $staging->update(['status' => 'ready', 'error' => $this->prepare($staging), 'copied_at' => now()]);
                } elseif ($clone->status === 'failed') {
                    $staging->update(['status' => 'failed', 'error' => $clone->error ?: __('The copy failed.')]);
                }
            }
        }
        if ($push = $staging->runningPush()) {
            $this->syncPush($push);
        }
    }

    /** Marks the copy as staging on the server. Returns a warning, or null. */
    private function prepare(Staging $staging): ?string
    {
        try {
            $this->agent->call('wp.staging_prepare', ['staging' => $staging->staging_domain, 'path' => $staging->path], 120);

            return null;
        } catch (AgentException $e) {
            report($e);

            return __('The copy works, but could not be marked as staging: :error', ['error' => $e->getMessage()]);
        }
    }

    /** What a push would do (tables, tables to keep, problems), or ['error' => ...]. */
    public function plan(Staging $staging): array
    {
        if (! $staging->isReady()) {
            return ['error' => __('The staging copy is not ready yet.')];
        }
        try {
            return $this->agent->call('wp.staging_plan', [
                'staging' => $staging->staging_domain, 'live' => $staging->live_domain, 'path' => $staging->path,
            ], 120);
        } catch (AgentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Backups of the live website made before each push. */
    public function backups(Staging $staging): array
    {
        try {
            return $this->agent->call('wp.staging_backups', ['live' => $staging->live_domain], 30)['backups'] ?? [];
        } catch (AgentException $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param  array{files?: bool, database?: bool, keep_uploads?: bool, keep_tables?: list<string>}  $options
     */
    public function push(Staging $staging, array $options, ?int $userId = null): StagingPush
    {
        $this->assertIdle($staging);
        [$live] = $this->sites($staging);
        $params = [
            'staging' => $staging->staging_domain, 'live' => $staging->live_domain, 'path' => $staging->path,
            'files' => (bool) ($options['files'] ?? true), 'database' => (bool) ($options['database'] ?? true),
            'keep_uploads' => (bool) ($options['keep_uploads'] ?? true),
            'keep_tables' => array_values(array_map('strval', $options['keep_tables'] ?? [])),
        ];
        if (! $params['files'] && ! $params['database']) {
            throw new SubdomainException(__('Choose what to push: files, database or both.'));
        }
        try {
            $job = $this->agent->call('wp.staging_push', $params, 120);
        } catch (AgentException $e) {
            throw new SubdomainException($e->getMessage());
        }
        $push = $staging->pushes()->create([
            'action' => 'push', 'status' => 'running', 'job_id' => $job['job'] ?? null, 'user_id' => $userId,
            'options' => array_diff_key($params, array_flip(['staging', 'live', 'path'])), 'started_at' => now(),
        ]);
        ActivityLog::record('wordpress.staging_pushed', $live->domain, [
            'staging' => $staging->staging_domain, 'files' => $params['files'], 'database' => $params['database'],
            'kept_tables' => count($params['keep_tables']),
        ], $live->customer_id);

        return $push;
    }

    /** Put a backup made before a push back on the live website. */
    public function restore(Staging $staging, string $backup, ?int $userId = null): StagingPush
    {
        $this->assertIdle($staging, false);
        $live = $staging->live;
        if ($live === null || $live->status !== 'active') {
            throw new SubdomainException(__('The live website is not active.'));
        }
        if (! collect($this->backups($staging))->contains('id', $backup)) {
            throw new SubdomainException(__('This backup no longer exists.'));
        }
        try {
            $job = $this->agent->call('wp.staging_restore', ['live' => $staging->live_domain, 'backup' => $backup], 120);
        } catch (AgentException $e) {
            throw new SubdomainException($e->getMessage());
        }
        $push = $staging->pushes()->create([
            'action' => 'restore', 'status' => 'running', 'job_id' => $job['job'] ?? null, 'backup' => $backup,
            'user_id' => $userId, 'started_at' => now(),
        ]);
        ActivityLog::record('wordpress.staging_restored', $live->domain, ['backup' => $backup], $live->customer_id);

        return $push;
    }

    /** Follow a push or restore job; the agent's answer while it runs, null when there is nothing to follow. */
    public function syncPush(StagingPush $push): ?array
    {
        if (! $push->busy() || ! $push->job_id) {
            return null;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $push->job_id], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $push->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            }

            return null;
        }
        if (($job['status'] ?? 'running') === 'running') {
            return $job;
        }
        $result = $job['result'] ?? [];
        if ($job['status'] === 'succeeded') {
            $push->update(['status' => 'succeeded', 'result' => $result, 'backup' => $push->backup ?? ($result['backup'] ?? null),
                'error' => null, 'finished_at' => now()]);
            if ($push->action === 'push') {
                $push->staging->update(['last_push_at' => now()]);
            }
            $this->rediscover($push->staging);
        } else {
            $details = $job['error']['details'] ?? [];
            $push->update(['status' => 'failed', 'error' => $job['error']['message'] ?? __('The push failed.'),
                'backup' => $push->backup ?? ($details['backup'] ?? null), 'rolled_back' => (bool) ($details['rolled_back'] ?? false),
                'finished_at' => now()]);
        }

        return $job;
    }

    /** Stop treating the copy as staging; with removeSite the staging subdomain goes as well. */
    public function remove(Staging $staging, bool $removeSite): void
    {
        $this->assertIdle($staging, false);
        $copy = $staging->staging;
        if ($removeSite && $copy !== null) {
            if (! $copy->isSubdomain()) {
                throw new SubdomainException(__('Only a staging copy on a subdomain can be removed here.'));
            }
            $this->subdomains->remove($copy);
        }
        ActivityLog::record('wordpress.staging_removed', $staging->live_domain, ['staging' => $staging->staging_domain, 'site_removed' => $removeSite], $staging->customer_id);
        $staging->delete();
    }

    private function rediscover(?Staging $staging): void
    {
        if ($staging?->live && class_exists(\Rhis\WordPress\Toolkit::class)) {
            try {
                app(\Rhis\WordPress\Toolkit::class)->discover($staging->live, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{0: Website, 1: Website} */
    private function sites(Staging $staging): array
    {
        $live = $staging->live;
        $copy = $staging->staging;
        if ($live === null || $copy === null) {
            throw new SubdomainException(__('The live website or the staging copy no longer exists.'));
        }
        if ($live->status !== 'active' || $copy->status !== 'active') {
            throw new SubdomainException(__('The live website and the staging copy must both be online.'));
        }
        if ($live->customer_id !== $copy->customer_id || $live->customer_id !== $staging->customer_id) {
            throw new SubdomainException(__('The live website and the staging copy belong to different customers.'));
        }

        return [$live, $copy];
    }

    private function assertIdle(Staging $staging, bool $needsReady = true): void
    {
        if ($needsReady && ! $staging->isReady()) {
            throw new SubdomainException(__('The staging copy is not ready yet.'));
        }
        if ($staging->status === 'copying' || $staging->runningPush()) {
            throw new SubdomainException(__('Wait until the current task is finished.'));
        }
        if (SiteClone::whereIn('status', ['waiting', 'running'])
            ->where(fn ($q) => $q->whereIn('source_website_id', array_filter([$staging->live_website_id, $staging->staging_website_id]))
                ->orWhereIn('target_website_id', array_filter([$staging->live_website_id, $staging->staging_website_id])))->exists()) {
            throw new SubdomainException(__('A clone of this website is already running.'));
        }
    }
}
