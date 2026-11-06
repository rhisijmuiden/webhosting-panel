<?php

declare(strict_types=1);

namespace Rhis\Subdomains;

use App\Models\ActivityLog;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\Subdomains\Models\SiteClone;

/**
 * Clone a website into another website of the same customer: files, databases
 * and configuration (the agent's site.clone job). A clone into a new subdomain
 * waits until that subdomain is set up on the server, then starts.
 */
final class CloneManager
{
    public function __construct(private readonly AgentClient $agent, private readonly SubdomainManager $subdomains) {}

    /** What the agent finds in the source: installs, databases, size and (with a target) whether it has files. */
    public function plan(Website $source, ?Website $target = null): array
    {
        try {
            return $this->agent->call('site.clone_plan', array_filter([
                'source' => $source->domain, 'target' => $target?->status === 'active' ? $target->domain : null,
            ]), 120);
        } catch (AgentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Websites of the same customer that the source can be cloned into. */
    public function targets(Website $source)
    {
        return Website::where('customer_id', $source->customer_id)->whereKeyNot($source->id)
            ->whereIn('status', ['active', 'provisioning'])->orderBy('domain')->get();
    }

    /**
     * @param  array{target?: ?Website, new_label?: ?string, replace?: bool, noindex?: bool, user_id?: ?int}  $options
     */
    public function start(Website $source, array $options): SiteClone
    {
        if ($source->status !== 'active') {
            throw new SubdomainException(__('The website is not active.'));
        }
        if (SiteClone::where(fn ($q) => $q->whereIn('source_website_id', [$source->id])->orWhereIn('target_website_id', [$source->id]))
            ->whereIn('status', ['waiting', 'running'])->exists()) {
            throw new SubdomainException(__('A clone of this website is already running.'));
        }
        $target = $options['target'] ?? null;
        if ($target === null) {
            // A new subdomain of the source's main website, set up like the source.
            $main = $source->isSubdomain() ? $source->parent : $source;
            $target = $this->subdomains->create($main, (string) ($options['new_label'] ?? ''), [
                'php_version' => $source->php_version, 'profile' => $source->profile, 'page_cache' => $source->page_cache,
            ]);
        }
        if ($target->customer_id !== $source->customer_id) {
            throw new SubdomainException(__('A website can only be cloned into another website of the same customer.'));
        }
        if ($target->is($source)) {
            throw new SubdomainException(__('Choose another website as the target.'));
        }
        if (SiteClone::where('target_website_id', $target->id)->whereIn('status', ['waiting', 'running'])->exists()) {
            throw new SubdomainException(__('A clone into :domain is already running.', ['domain' => $target->domain]));
        }

        $clone = SiteClone::create([
            'customer_id' => $source->customer_id, 'source_website_id' => $source->id, 'target_website_id' => $target->id,
            'source_domain' => $source->domain, 'target_domain' => $target->domain, 'status' => 'waiting',
            'replace' => (bool) ($options['replace'] ?? false), 'noindex' => (bool) ($options['noindex'] ?? true),
            'user_id' => $options['user_id'] ?? null,
        ]);
        ActivityLog::record('website.clone', $source->domain, ['target' => $target->domain, 'replace' => $clone->replace], $source->customer_id);
        $this->sync($clone);

        return $clone->fresh() ?? $clone;
    }

    /** Move a clone on: start it once the target is ready, follow the agent job, apply the result. */
    public function sync(SiteClone $clone): ?array
    {
        if ($clone->status === 'waiting') {
            $target = $clone->target;
            if ($target === null || in_array($target->status, ['failed', 'removing'], true)) {
                $clone->update(['status' => 'failed', 'finished_at' => now(),
                    'error' => $target?->last_error ?: __('The target website could not be set up.')]);

                return null;
            }
            if ($target->status !== 'active') {
                return null;
            }
            try {
                $job = $this->agent->call('site.clone', [
                    'source' => $clone->source_domain, 'target' => $clone->target_domain,
                    'replace' => $clone->replace, 'noindex' => $clone->noindex,
                ], 120);
            } catch (AgentException $e) {
                $clone->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);

                return null;
            }
            $clone->update(['status' => 'running', 'job_id' => $job['job'] ?? null, 'started_at' => now()]);
        }
        if ($clone->status !== 'running' || ! $clone->job_id) {
            return null;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $clone->job_id], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $clone->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            }

            return null;
        }
        if (($job['status'] ?? 'running') === 'running') {
            return $job;
        }
        if ($job['status'] === 'succeeded') {
            $this->applyResult($clone, $job['result'] ?? []);
            $clone->update(['status' => 'succeeded', 'result' => $job['result'] ?? [], 'error' => null, 'finished_at' => now()]);
        } else {
            $clone->update(['status' => 'failed', 'error' => $job['error']['message'] ?? __('The clone failed.'), 'finished_at' => now()]);
        }

        return $job;
    }

    /** @param iterable<SiteClone> $clones */
    public function syncAll(iterable $clones): void
    {
        foreach ($clones as $clone) {
            $this->sync($clone);
        }
    }

    private function applyResult(SiteClone $clone, array $result): void
    {
        $target = $clone->target;
        if ($target === null) {
            return;
        }
        foreach ((array) ($result['dropped_databases'] ?? []) as $name) {
            SiteDatabase::where('website_id', $target->id)->where('name', $name)->delete();
        }
        foreach ((array) ($result['databases'] ?? []) as $name) {
            SiteDatabase::updateOrCreate(['name' => $name], ['website_id' => $target->id, 'users' => [$name], 'origin' => 'clone']);
        }
        $kinds = collect($result['installs'] ?? [])->pluck('kind');
        if ($kinds->contains('wordpress') && class_exists(\Rhis\WordPress\Toolkit::class)) {
            try {
                app(\Rhis\WordPress\Toolkit::class)->discover($target, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if (isset($result['size_mb'])) {
            $target->update(['disk_used_mb' => (int) $result['size_mb']]);
        }
    }
}
