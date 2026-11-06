<?php

declare(strict_types=1);

namespace Rhis\Git\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One deployment of a repository: who started it, what was deployed and the log. */
class GitDeployment extends Model
{
    protected $table = 'git_deployments';

    protected $fillable = ['git_repository_id', 'trigger', 'user', 'job_id', 'status', 'commit', 'result', 'log', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'log' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function pill(): string
    {
        return ['succeeded' => 'ok', 'failed' => 'bad', 'running' => 'info', 'queued' => 'neutral'][$this->status] ?? 'neutral';
    }

    public function seconds(): ?float
    {
        return $this->result['seconds'] ?? ($this->started_at && $this->finished_at ? $this->finished_at->diffInSeconds($this->started_at, true) : null);
    }
}
