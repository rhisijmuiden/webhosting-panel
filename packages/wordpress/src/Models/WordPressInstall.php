<?php

declare(strict_types=1);

namespace Rhis\WordPress\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One WordPress install on a website: the docroot (path '') or a folder below it. */
class WordPressInstall extends Model
{
    protected $table = 'wordpress_installs';

    protected $fillable = [
        'website_id', 'path', 'version', 'core_update', 'plugins', 'themes', 'outdated_plugins', 'outdated_themes',
        'cron_mode', 'cron_interval', 'checked_at', 'last_error', 'last_update_at', 'last_update_status', 'last_update_summary',
    ];

    protected function casts(): array
    {
        return [
            'plugins' => 'array', 'themes' => 'array', 'last_update_summary' => 'array',
            'checked_at' => 'datetime', 'last_update_at' => 'datetime',
            'outdated_plugins' => 'integer', 'outdated_themes' => 'integer', 'cron_interval' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** Installs with anything to update. */
    public function scopeOutdated(Builder $q): Builder
    {
        return $q->where(fn ($q) => $q->whereNotNull('core_update')->orWhere('outdated_plugins', '>', 0)->orWhere('outdated_themes', '>', 0));
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('website', fn ($w) => $w->where('customer_id', $customerId));
    }

    public function label(): string
    {
        return $this->website->domain.($this->path !== '' ? '/'.$this->path : '');
    }

    public function url(): string
    {
        $scheme = $this->website->ssl_status === 'active' ? 'https' : 'http';

        return $scheme.'://'.$this->label();
    }

    public function outdatedCount(): int
    {
        return ($this->core_update ? 1 : 0) + $this->outdated_plugins + $this->outdated_themes;
    }

    public function isOutdated(): bool
    {
        return $this->outdatedCount() > 0;
    }

    /** The agent params that identify this install. */
    public function target(): array
    {
        return ['domain' => $this->website->domain, 'path' => $this->path];
    }

    /** Store a wp.status answer from the agent. */
    public function fillFromStatus(array $s): void
    {
        $this->fill([
            'version' => $s['version'] ?? $this->version,
            'core_update' => $s['core_update']['version'] ?? null,
            'plugins' => $s['plugins'] ?? [],
            'themes' => $s['themes'] ?? [],
            'outdated_plugins' => (int) ($s['outdated']['plugins'] ?? 0),
            'outdated_themes' => (int) ($s['outdated']['themes'] ?? 0),
            'cron_mode' => $s['cron']['mode'] ?? $this->cron_mode,
            'cron_interval' => $s['cron']['interval'] ?? null,
            'checked_at' => now(),
            'last_error' => ! empty($s['errors']) ? implode("\n", $s['errors']) : null,
        ]);
    }
}
