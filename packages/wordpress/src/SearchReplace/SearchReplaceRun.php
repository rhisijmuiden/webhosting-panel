<?php

declare(strict_types=1);

namespace Rhis\WordPress\SearchReplace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** One search & replace or domain change: a dry run, and the real change once confirmed. */
class SearchReplaceRun extends Model
{
    /** A dry run older than this must be done again before it can be applied. */
    public const PREVIEW_VALID_MINUTES = 60;

    protected $table = 'wp_search_replace_runs';

    protected $fillable = [
        'wordpress_install_id', 'user_id', 'mode', 'params', 'status', 'preview_job', 'apply_job',
        'preview', 'result', 'error', 'previewed_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array', 'preview' => 'array', 'result' => 'array',
            'previewed_at' => 'datetime', 'applied_at' => 'datetime',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['previewing', 'applying'], true);
    }

    public function canApply(): bool
    {
        return $this->status === 'previewed'
            && (int) ($this->preview['total'] ?? 0) > 0
            && $this->previewed_at?->gt(now()->subMinutes(self::PREVIEW_VALID_MINUTES));
    }

    public function isExpired(): bool
    {
        return $this->status === 'previewed' && ! $this->canApply() && (int) ($this->preview['total'] ?? 0) > 0;
    }

    /** "old → new" for lists and the activity log. */
    public function summary(): string
    {
        $p = $this->params;
        if ($this->mode === 'domain') {
            return ($this->preview['old_home'] ?? $this->result['old_home'] ?? '?').' → '.($p['scheme'] ?? 'https').'://'.($p['host'] ?? '?');
        }

        return '"'.mb_strimwidth((string) ($p['search'] ?? ''), 0, 60, '…').'" → "'.mb_strimwidth((string) ($p['replace'] ?? ''), 0, 60, '…').'"';
    }
}
