<?php

declare(strict_types=1);

namespace Rhis\Git\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A git repository linked to a website, deployed to a folder of that website. */
class GitRepository extends Model
{
    protected $table = 'git_repositories';

    protected $fillable = [
        'uuid', 'website_id', 'name', 'url', 'provider', 'branch', 'deploy_path', 'mode', 'auto_deploy', 'steps', 'shared',
        'keep_releases', 'webhook_secret', 'public_key', 'fingerprint', 'latest_commit', 'deployed_commit',
        'current_release', 'status', 'last_error', 'last_deployed_at', 'last_webhook_at',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'auto_deploy' => 'boolean', 'steps' => 'array', 'shared' => 'array', 'keep_releases' => 'integer',
            'webhook_secret' => 'encrypted', 'latest_commit' => 'array', 'deployed_commit' => 'array',
            'last_deployed_at' => 'datetime', 'last_webhook_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(GitDeployment::class)->latest()->latest('id');
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('website', fn ($w) => $w->where('customer_id', $customerId));
    }

    public static function providerFor(string $url): string
    {
        $host = strtolower((string) (preg_match('#^(?:https://|ssh://)?(?:[^@/]+@)?([^/:]+)#', $url, $m) ? $m[1] : ''));

        return match (true) {
            str_contains($host, 'github') => 'github',
            str_contains($host, 'gitlab') => 'gitlab',
            str_contains($host, 'bitbucket') => 'bitbucket',
            str_contains($host, 'gitea') || str_contains($host, 'forgejo') || str_contains($host, 'codeberg') => 'gitea',
            default => 'other',
        };
    }

    public function providerLabel(): string
    {
        return ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'gitea' => 'Gitea / Forgejo'][$this->provider] ?? __('Git server');
    }

    /** The web page of the repository (for links), when the URL allows it. */
    public function webUrl(): ?string
    {
        if (preg_match('#^(?:https://|ssh://)?(?:[^@/]+@)?([^/:]+)(?::\d+)?[:/](.+?)(?:\.git)?$#', $this->url, $m) && $this->provider !== 'other') {
            return 'https://'.$m[1].'/'.ltrim($m[2], '/');
        }

        return str_starts_with($this->url, 'https://') ? preg_replace('#\.git$#', '', $this->url) : null;
    }

    public function commitUrl(?string $sha): ?string
    {
        $web = $this->webUrl();
        if (! $web || ! $sha) {
            return null;
        }

        return match ($this->provider) {
            'gitlab' => "{$web}/-/commit/{$sha}",
            'bitbucket' => "{$web}/commits/{$sha}",
            default => "{$web}/commit/{$sha}",
        };
    }

    public function isSsh(): bool
    {
        return ! str_starts_with($this->url, 'https://');
    }

    public function webhookUrl(): string
    {
        return route('git.webhook', $this->uuid);
    }

    public function hasNewCommits(): bool
    {
        return ($this->latest_commit['commit'] ?? null) && ($this->latest_commit['commit'] ?? null) !== ($this->deployed_commit['commit'] ?? null);
    }

    public function stepTypes(): array
    {
        return collect($this->steps ?? [])->pluck('type')->all();
    }

    public function customCommand(): string
    {
        return (string) (collect($this->steps ?? [])->firstWhere('type', 'command')['command'] ?? '');
    }

    /** The agent params that identify this repository and its settings. */
    public function target(): array
    {
        return [
            'domain' => $this->website->domain, 'id' => $this->uuid, 'url' => $this->url, 'branch' => $this->branch,
            'path' => $this->deploy_path, 'mode' => $this->mode, 'keep' => $this->keep_releases,
            'shared' => $this->mode === 'releases' ? array_values($this->shared ?? []) : [],
        ];
    }

    public function label(): string
    {
        return $this->website->domain.' · '.$this->name;
    }
}
