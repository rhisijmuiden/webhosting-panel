<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** Last known state of the quick switches of one WordPress install (the truth lives in WordPress). */
class SwitchState extends Model
{
    protected $table = 'wordpress_switches';

    protected $fillable = [
        'wordpress_install_id', 'maintenance', 'maintenance_since', 'maintenance_title', 'maintenance_message',
        'debug', 'debug_log', 'debug_until', 'indexing', 'log_size', 'public_log_size', 'cache_cleared_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'maintenance' => 'boolean', 'debug' => 'boolean', 'indexing' => 'boolean',
            'maintenance_since' => 'datetime', 'debug_until' => 'datetime', 'cache_cleared_at' => 'datetime', 'checked_at' => 'datetime',
            'log_size' => 'integer', 'public_log_size' => 'integer',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    /** Copy an agent answer (wp.switches and the *_set actions). */
    public function fillFromAgent(array $s): static
    {
        $this->fill([
            'maintenance' => (bool) ($s['maintenance'] ?? false),
            'maintenance_since' => ! empty($s['maintenance_since']) ? \Illuminate\Support\Carbon::createFromTimestamp($s['maintenance_since']) : null,
            'maintenance_title' => ($s['maintenance_title'] ?? '') ?: $this->maintenance_title,
            'maintenance_message' => ($s['maintenance_message'] ?? '') ?: $this->maintenance_message,
            'debug' => (bool) ($s['debug'] ?? false),
            'debug_log' => $s['debug_log'] ?? null,
            'indexing' => (bool) ($s['indexing'] ?? true),
            'log_size' => (int) ($s['log']['size'] ?? 0),
            'public_log_size' => isset($s['public_log']['size']) ? (int) $s['public_log']['size'] : null,
            'checked_at' => now(),
        ]);
        if (! $this->debug) {
            $this->debug_until = null;
        }

        return $this;
    }

    public function isStale(): bool
    {
        return ! $this->checked_at || $this->checked_at->lt(now()->subMinutes((int) config('wordpress-switches.stale_minutes', 10)));
    }
}
