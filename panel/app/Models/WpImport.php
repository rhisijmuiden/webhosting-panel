<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WordPress import: two uploaded files (website zip, database export),
 * the agent's check report, the customer's confirmed choices and the result.
 */
class WpImport extends Model
{
    public const KINDS = ['site', 'database'];

    protected $fillable = [
        'uuid', 'customer_id', 'website_id', 'domain', 'user_id', 'status', 'files', 'report', 'options', 'result',
        'log', 'job_id', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array', 'report' => 'array', 'options' => 'array', 'result' => 'array', 'log' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function busy(): bool
    {
        return in_array($this->status, ['checking', 'running'], true);
    }

    public function file(string $kind): ?array
    {
        return $this->files[$kind] ?? null;
    }

    public function uploaded(string $kind): bool
    {
        return (bool) ($this->file($kind)['complete'] ?? false);
    }

    public function bothUploaded(): bool
    {
        return $this->uploaded('site') && $this->uploaded('database');
    }

    /** The check found nothing that blocks the import (agent + panel checks). */
    public function checkPassed(): bool
    {
        return (bool) ($this->report['ok'] ?? false)
            && collect($this->report['checks'] ?? [])->doesntContain(fn ($c) => ($c['state'] ?? '') === 'bad');
    }

    /** 1 upload, 2 check, 3 confirm, 4 import, 5 done: the step of the wizard header. */
    public function step(): int
    {
        return match ($this->status) {
            'upload' => 1, 'checking' => 2, 'checked' => 3, 'running' => 4, 'done' => 5,
            default => $this->result || $this->started_at ? 4 : ($this->report ? 3 : 2),
        };
    }

    /** The step the running agent job is at: files, database, config, replace, check. */
    public function jobStep(): ?string
    {
        foreach (array_reverse($this->log ?? []) as $line) {
            if (preg_match('/^step: (\w+)/', (string) $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
