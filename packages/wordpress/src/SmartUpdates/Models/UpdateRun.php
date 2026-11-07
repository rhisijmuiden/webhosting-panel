<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rhis\WordPress\Models\WordPressInstall;

/** One smart update of a WordPress install: what changed, the screenshots and the outcome. */
class UpdateRun extends Model
{
    public const PROBLEMS = ['rolled_back', 'failed'];

    protected $table = 'wp_update_runs';

    protected $fillable = ['wordpress_install_id', 'uid', 'mode', 'trigger', 'status', 'reason', 'job', 'started_by',
        'started_at', 'finished_at', 'pending', 'updated', 'pages', 'max_score', 'threshold', 'health', 'notes', 'log',
        'error', 'mailed_at'];

    protected function casts(): array
    {
        return ['pending' => 'array', 'updated' => 'array', 'pages' => 'array', 'health' => 'array', 'notes' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'mailed_at' => 'datetime',
            'max_score' => 'float', 'threshold' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $run) => Storage::disk('local')->deleteDirectory($run->storageDir()));
    }

    /** URLs use the random uid, not the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    public static function newUid(): string
    {
        return strtolower(Str::random(24));
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function scopeRunning(Builder $q): Builder
    {
        return $q->where('status', 'running');
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('install', fn ($i) => $i->forCustomer($customerId));
    }

    public function isProblem(): bool
    {
        return in_array($this->status, self::PROBLEMS, true);
    }

    public function storageDir(): string
    {
        return 'wp-smart-updates/'.$this->uid;
    }

    public function imagePath(string $name): string
    {
        return $this->storageDir().'/'.$name;
    }

    public function hasImage(string $name): bool
    {
        return preg_match('/^p[0-9]-(desktop|mobile)-(before|after|diff|restored)\.jpg$/', $name) === 1
            && Storage::disk('local')->exists($this->imagePath($name));
    }

    /** Pill status for x-ui.pill. */
    public function pill(): string
    {
        return match ($this->status) {
            'updated', 'up_to_date' => 'ok',
            'rolled_back', 'notified' => 'warn',
            'failed' => 'bad',
            'running' => 'info',
            default => 'neutral',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'running' => __('Running'),
            'updated' => __('Updated'),
            'rolled_back' => __('Rolled back'),
            'failed' => __('Failed'),
            'notified' => __('Updates waiting'),
            'up_to_date' => __('Up to date'),
            default => ucfirst($this->status),
        };
    }

    public function reasonLabel(): ?string
    {
        return match ($this->reason) {
            'visual' => __('The website looked different after the update'),
            'health' => __('The website gave an error after the update'),
            'error' => __('The update could not be finished'),
            default => null,
        };
    }

    /** Number of parts that were updated (or are waiting, for a notify run). */
    public function changeCount(): int
    {
        $u = $this->status === 'notified' ? ($this->pending ?? []) : ($this->updated ?? []);

        return (! empty($u['core']) ? 1 : 0) + count($u['plugins'] ?? []) + count($u['themes'] ?? []);
    }

    /** The changes as a flat list of [type, name, from, to] for the screens and the mail. */
    public function changes(): array
    {
        $out = [];
        if ($this->status === 'notified' || in_array($this->status, ['running'], true)) {
            $p = $this->pending ?? [];
            if (! empty($p['core'])) {
                $out[] = ['type' => 'core', 'name' => 'WordPress', 'from' => $p['core']['from'] ?? null, 'to' => $p['core']['to'] ?? null];
            }
            foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
                foreach ($p[$key] ?? [] as $i) {
                    $out[] = ['type' => $type, 'name' => html_entity_decode(strip_tags((string) ($i['title'] ?? $i['name']))), 'from' => $i['from'] ?? null, 'to' => $i['to'] ?? null];
                }
            }

            return $out;
        }
        $u = $this->updated ?? [];
        $titles = collect($this->pending['plugins'] ?? [])->merge($this->pending['themes'] ?? [])->pluck('title', 'name');
        if (! empty($u['core'])) {
            $out[] = ['type' => 'core', 'name' => 'WordPress', 'from' => $u['core']['old_version'] ?? null, 'to' => $u['core']['new_version'] ?? null];
        }
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            foreach ($u[$key] ?? [] as $i) {
                $out[] = ['type' => $type, 'name' => html_entity_decode(strip_tags((string) ($titles[$i['name']] ?? $i['name']))),
                    'from' => $i['old_version'] ?? null, 'to' => $i['new_version'] ?? null];
            }
        }

        return $out;
    }

    public function duration(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }
        $s = (int) $this->started_at->diffInSeconds($this->finished_at);

        return $s < 90 ? $s.' s' : round($s / 60).' min';
    }
}
