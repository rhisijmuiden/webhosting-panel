<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\SmartUpdates\Options;

/** What the scheduled update may do for one WordPress install. */
class UpdatePolicy extends Model
{
    public const MODES = ['all', 'security', 'notify', 'off'];

    public const MAILS = ['always', 'problems', 'never'];

    public const MAX_PAGES = 4;

    protected $table = 'wp_update_policies';

    protected $fillable = ['wordpress_install_id', 'mode', 'visual_check', 'threshold', 'pages', 'mail',
        'last_window', 'last_checked_at', 'notified_hash'];

    protected $attributes = ['visual_check' => true, 'mail' => 'always'];

    protected function casts(): array
    {
        return ['pages' => 'array', 'visual_check' => 'boolean', 'threshold' => 'integer', 'last_checked_at' => 'datetime'];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    /** The policy of an install (a new, unsaved one when it has none). */
    public static function for(WordPressInstall $install): self
    {
        return self::firstOrNew(['wordpress_install_id' => $install->id]);
    }

    public function effectiveMode(): string
    {
        return $this->mode ?? app(Options::class)->get('default_mode');
    }

    public function usesDefault(): bool
    {
        return $this->mode === null;
    }

    public function effectiveThreshold(): int
    {
        return $this->threshold ?? (int) app(Options::class)->get('threshold');
    }

    public function visualEnabled(): bool
    {
        return $this->visual_check && app(Options::class)->get('visual');
    }

    /** The pages that are photographed: always the home page, plus the extra ones. */
    public function pageList(): array
    {
        return array_values(array_unique(['/', ...array_slice($this->pages ?? [], 0, self::MAX_PAGES)]));
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'all' => __('All updates'),
            'security' => __('Security updates only'),
            'notify' => __('Notify only'),
            default => __('Off'),
        };
    }

    public static function modeHelp(string $mode): string
    {
        return match ($mode) {
            'all' => __('WordPress, plugins and themes are updated every night in the maintenance window.'),
            'security' => __('Only security releases of WordPress and plugins or themes with a known vulnerability.'),
            'notify' => __('Nothing is changed; you get a mail when updates are waiting.'),
            default => __('No scheduled updates and no mails for this site.'),
        };
    }

    public static function modeIcon(string $mode): string
    {
        return match ($mode) {
            'all' => 'refresh',
            'security' => 'shield',
            'notify' => 'mail',
            default => 'pause',
        };
    }
}
