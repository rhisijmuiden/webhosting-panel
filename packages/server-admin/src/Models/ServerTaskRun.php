<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\ServerAdmin\Tasks;

/** One run of a task, an update, a reboot or a maintenance session, with its log. */
class ServerTaskRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['task', 'trigger', 'status', 'user_id', 'agent_job', 'apt_run', 'summary', 'params', 'result', 'log', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['params' => 'array', 'result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRunning(Builder $q): Builder
    {
        return $q->where('status', 'running');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function appendLog(string ...$lines): void
    {
        $stamp = now(config('server-admin.timezone'))->format('H:i:s');
        $text = implode("\n", array_map(fn ($l) => "[$stamp] $l", $lines));
        $this->log = ltrim(($this->log ?? '')."\n".$text, "\n");
        if (strlen($this->log) > 400000) {
            $this->log = substr($this->log, -400000);
        }
        $this->save();
    }

    public function finish(string $status, ?string $summary = null, ?array $result = null): void
    {
        $this->status = $status;
        $this->finished_at = now();
        if ($summary !== null) {
            $this->summary = mb_substr($summary, 0, 500);
        }
        if ($result !== null) {
            $this->result = $result;
        }
        $this->save();
    }

    public function label(): string
    {
        return Tasks::label($this->task);
    }

    public function durationLabel(): ?string
    {
        if (! $this->finished_at) {
            return null;
        }
        $s = max(0, $this->finished_at->getTimestamp() - $this->started_at->getTimestamp());

        return $s < 90 ? $s.' s' : ($s < 5400 ? round($s / 60).' min' : round($s / 3600, 1).' h');
    }
}
