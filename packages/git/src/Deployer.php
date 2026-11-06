<?php

declare(strict_types=1);

namespace Rhis\Git;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rhis\Git\Models\GitDeployment;
use Rhis\Git\Models\GitRepository;

/** Everything the screens and the webhook do with repositories goes through here, and from here to the agent (git.*). */
final class Deployer
{
    public function __construct(private readonly AgentClient $agent) {}

    /** The folder a new repository deploys to by default: the docroot, or app/ for Laravel. */
    public static function defaultPath(Website $website): string
    {
        return $website->profile === 'laravel' ? 'app' : 'public';
    }

    /** Files and folders kept between releases, by website type. */
    public static function defaultShared(Website $website): array
    {
        return match ($website->profile) {
            'laravel' => ['storage/', '.env'],
            'wordpress' => ['wp-content/uploads/', 'wp-config.php'],
            default => [],
        };
    }

    public static function defaultSteps(Website $website): array
    {
        return match ($website->profile) {
            'laravel' => [['type' => 'composer'], ['type' => 'npm'], ['type' => 'artisan_migrate'], ['type' => 'artisan_optimize']],
            default => [['type' => 'composer']],
        };
    }

    /** Link a repository: store it and make its deploy key on the server. */
    public function create(Website $website, array $data): GitRepository
    {
        $repo = new GitRepository($data + [
            'website_id' => $website->id,
            'keep_releases' => (int) config('git.keep_releases', 5),
        ]);
        $repo->uuid = (string) Str::uuid();
        $repo->provider = GitRepository::providerFor($repo->url);
        $repo->webhook_secret = Str::random(40);
        $repo->setRelation('website', $website);
        $this->storeKey($repo, $this->agent->call('git.key', ['domain' => $website->domain, 'id' => $repo->uuid], 60));
        $repo->save();

        return $repo;
    }

    public function rotateKey(GitRepository $repo): GitRepository
    {
        $this->storeKey($repo, $this->agent->call('git.key', ['domain' => $repo->website->domain, 'id' => $repo->uuid, 'rotate' => true], 60));
        $repo->save();

        return $repo;
    }

    private function storeKey(GitRepository $repo, array $key): void
    {
        $repo->public_key = $key['public_key'] ?? null;
        $repo->fingerprint = $key['fingerprint'] ?? null;
    }

    public function rotateSecret(GitRepository $repo): GitRepository
    {
        $repo->update(['webhook_secret' => Str::random(40)]);

        return $repo;
    }

    /** Test access and list the branches: ['branches' => [['name', 'commit']], 'default' => 'main']. */
    public function check(GitRepository $repo): array
    {
        $out = $this->agent->call('git.check', ['domain' => $repo->website->domain, 'id' => $repo->uuid, 'url' => $repo->url], 90);
        $branch = collect($out['branches'] ?? [])->firstWhere('name', $repo->branch);
        if ($branch && ($repo->latest_commit['commit'] ?? null) !== $branch['commit']) {
            $repo->update(['latest_commit' => ['commit' => $branch['commit'], 'short' => substr($branch['commit'], 0, 7)]]);
        }

        return $out;
    }

    /** Fetch the branch and remember its newest commit, without deploying. */
    public function fetchLatest(GitRepository $repo): ?array
    {
        $out = $this->agent->call('git.fetch', $this->identity($repo) + ['url' => $repo->url, 'branch' => $repo->branch], 900);
        $repo->update(['latest_commit' => $out['latest'] ?? null]);

        return $out['latest'] ?? null;
    }

    public function status(GitRepository $repo): array
    {
        return $this->agent->call('git.status', $this->identity($repo) + ['path' => $repo->deploy_path], 30);
    }

    /**
     * Ask for a deployment. When one is already running for this repository the new one waits
     * (one queued deployment at most: a burst of pushes gives one extra deployment, of the newest commit).
     */
    public function deploy(GitRepository $repo, string $trigger = 'manual', ?string $user = null, ?string $commit = null): GitDeployment
    {
        return DB::transaction(function () use ($repo, $trigger, $user, $commit) {
            GitRepository::whereKey($repo->id)->lockForUpdate()->first();
            $queued = $repo->deployments()->where('status', 'queued')->first();
            if ($queued) {
                $queued->update(['trigger' => $trigger, 'user' => $user, 'commit' => $commit]);

                return $queued;
            }
            $deployment = $repo->deployments()->create(['trigger' => $trigger, 'user' => $user, 'commit' => $commit, 'status' => 'queued']);
            if (! $repo->deployments()->where('status', 'running')->exists()) {
                $this->start($deployment);
            }

            return $deployment->refresh();
        });
    }

    private function start(GitDeployment $deployment): void
    {
        $repo = $deployment->repository;
        $params = $repo->target() + [
            'steps' => array_values($repo->steps ?? []),
            'allow_commands' => $this->commandsAllowed($repo),
        ] + array_filter(['commit' => $deployment->commit]);
        try {
            $job = $this->agent->call('git.deploy', $params, 30)['job'];
        } catch (AgentException $e) {
            $deployment->update(['status' => 'failed', 'error' => $e->getMessage(), 'started_at' => now(), 'finished_at' => now()]);
            $repo->update(['status' => 'failed', 'last_error' => $e->getMessage()]);

            return;
        }
        $deployment->update(['status' => 'running', 'job_id' => $job, 'started_at' => now()]);
        $repo->update(['status' => 'deploying']);
    }

    /** Custom commands only run when they are allowed for this repository's owner. */
    public function commandsAllowed(GitRepository $repo): bool
    {
        return (bool) config('git.customer_commands') || $repo->website->customer_id === null;
    }

    /** Follow a running deployment; store the outcome once it is finished and start the next one. */
    public function follow(GitDeployment $deployment): GitDeployment
    {
        if ($deployment->status !== 'running' || ! $deployment->job_id) {
            return $deployment;
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $deployment->job_id], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $this->finish($deployment, ['status' => 'failed', 'error' => ['message' => __('The server lost track of this deployment.')]]);
            }

            return $deployment;
        }
        if (($state['status'] ?? 'running') === 'running') {
            $deployment->update(['log' => $state['log'] ?? []]);

            return $deployment;
        }
        $this->finish($deployment, $state);

        return $deployment->refresh();
    }

    private function finish(GitDeployment $deployment, array $state): void
    {
        $ok = ($state['status'] ?? '') === 'succeeded';
        $result = $state['result'] ?? null;
        $error = $state['error']['message'] ?? null;
        $deployment->update([
            'status' => $ok ? 'succeeded' : 'failed', 'log' => $state['log'] ?? $deployment->log,
            'result' => $result, 'error' => $ok ? null : ($error ?: __('The deployment failed.')), 'finished_at' => now(),
        ]);
        $repo = $deployment->repository;
        if ($ok) {
            $repo->update([
                'status' => 'ok', 'last_error' => null, 'deployed_commit' => $result['commit'] ?? null,
                'latest_commit' => $this->newer($repo->latest_commit, $result['commit'] ?? null),
                'current_release' => $result['release'] ?? null, 'last_deployed_at' => now(),
            ]);
        } else {
            $repo->update(['status' => 'failed', 'last_error' => $deployment->error]);
        }
        $this->prune($repo);
        if ($next = $repo->deployments()->where('status', 'queued')->oldest('id')->first()) {
            $this->start($next);
        }
    }

    /** After a deploy of the newest commit, the deployed commit is also the latest one we know. */
    private function newer(?array $latest, ?array $deployed): ?array
    {
        return $deployed && (! $latest || ($latest['commit'] ?? null) === ($deployed['commit'] ?? null) || ($latest['date'] ?? 0) <= ($deployed['date'] ?? 0))
            ? $deployed : $latest;
    }

    private function prune(GitRepository $repo): void
    {
        $keep = max(5, (int) config('git.keep_deployments', 50));
        $ids = $repo->deployments()->skip($keep)->take(1000)->pluck('id');
        if ($ids->isNotEmpty()) {
            GitDeployment::whereIn('id', $ids)->delete();
        }
    }

    /** Finish deployments nobody is watching and start queued ones (scheduled every minute). */
    public function collect(): int
    {
        $n = 0;
        foreach (GitDeployment::with('repository.website')->where('status', 'running')->get() as $d) {
            $this->follow($d)->isFinished() && $n++;
        }
        foreach (GitDeployment::with('repository.website')->where('status', 'queued')->get() as $d) {
            if (! GitDeployment::where('git_repository_id', $d->git_repository_id)->where('status', 'running')->exists()) {
                $this->start($d);
            }
        }

        return $n;
    }

    public function rollback(GitRepository $repo, string $release, ?string $user = null): array
    {
        $out = $this->agent->call('git.rollback', $this->identity($repo) + ['path' => $repo->deploy_path, 'release' => $release], 120);
        $repo->deployments()->create([
            'trigger' => 'rollback', 'user' => $user, 'status' => 'succeeded', 'log' => $out['log'] ?? [],
            'result' => ['release' => $release, 'commit' => ['short' => explode('-', $release, 2)[1] ?? '']],
            'started_at' => now(), 'finished_at' => now(),
        ]);
        $repo->update(['current_release' => $release]);

        return $out;
    }

    public function remove(GitRepository $repo): array
    {
        $out = $this->agent->call('git.remove', $this->identity($repo) + [
            'path' => $repo->deploy_path, 'shared' => array_values($repo->shared ?? []),
        ], 1800);
        $repo->delete();

        return $out;
    }

    private function identity(GitRepository $repo): array
    {
        return ['domain' => $repo->website->domain, 'id' => $repo->uuid];
    }
}
