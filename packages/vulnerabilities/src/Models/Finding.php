<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** One problem found on a website: a vulnerable core/plugin/theme version or a malware hit. */
class Finding extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /** Points per open finding for the customer risk score. */
    public const WEIGHTS = ['critical' => 40, 'high' => 20, 'medium' => 8, 'low' => 2, 'unknown' => 4];

    protected $table = 'security_findings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'references' => 'array', 'cvss_score' => 'float', 'notify_count' => 'integer',
            'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'notified_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('security_findings.status', 'open');
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('website', fn ($w) => $w->where('customer_id', $customerId));
    }

    /** Findings at or above a severity; malware always counts. */
    public function scopeAtLeast(Builder $q, string $severity): Builder
    {
        $levels = self::levelsFrom($severity);

        return $q->where(fn ($q) => $q->whereIn('security_findings.severity', $levels)->orWhere('security_findings.kind', 'malware'));
    }

    public static function levelsFrom(string $severity): array
    {
        $order = ['critical', 'high', 'medium', 'low'];
        $i = array_search($severity, $order, true);
        $levels = array_slice($order, 0, $i === false ? 4 : $i + 1);

        return $severity === 'low' ? [...$levels, 'unknown'] : $levels;
    }

    public static function rank(string $severity): int
    {
        return ['critical' => 0, 'high' => 1, 'medium' => 2, 'unknown' => 3, 'low' => 4][$severity] ?? 5;
    }

    public function weight(): float
    {
        if ($this->kind === 'malware') {
            return 50;
        }
        $w = self::WEIGHTS[$this->severity] ?? 4;

        return $this->fixed_version === null ? $w * 1.25 : $w;
    }

    public function isMalware(): bool
    {
        return $this->kind === 'malware';
    }

    /** Core or plugin files that differ from WordPress.org (rhis/wp-integrity). */
    public function isIntegrity(): bool
    {
        return $this->kind === 'integrity';
    }

    /** Integrity findings store an English sentence as title; show it in the user's language. */
    protected function title(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn ($value) => $this->kind === 'integrity' && is_string($value) ? __($value) : $value);
    }

    /** Where the customer fixes this finding in the client panel, if there is a better place than the list. */
    public function clientFixUrl(): ?string
    {
        if ($this->isIntegrity() && $this->install && \Illuminate\Support\Facades\Route::has('client.wp-integrity.show') && config('wp-integrity.customer')) {
            return route('client.wp-integrity.show', $this->install);
        }
        if (! $this->isMalware() && ! $this->isIntegrity() && $this->install && config('wordpress.customer_toolkit') && \Illuminate\Support\Facades\Route::has('client.wordpress.show')) {
            return route('client.wordpress.show', $this->install);
        }

        return null;
    }

    /** "Contact Form 7 5.8.1" or the file name. */
    public function componentLabel(): string
    {
        if ($this->isMalware()) {
            return (string) $this->file_path;
        }
        $name = $this->component_type === 'core' ? 'WordPress' : ($this->component_name ?: $this->component_slug);

        return trim($name.' '.$this->installed_version);
    }

    public function siteLabel(): string
    {
        return $this->install?->label() ?? $this->website->domain;
    }

    /** What the customer should do, in the current locale. */
    public function advice(): string
    {
        if ($this->isMalware()) {
            return __('Remove or replace this file and change the passwords of the website (FTP, WordPress administrators, database). If the file belongs to a plugin, reinstall that plugin from a clean copy.');
        }
        if ($this->isIntegrity()) {
            return __('These files differ from the original on WordPress.org. Restore the original files with one click in the control panel. If you did not make the change yourself, also change the passwords of the website.');
        }
        $what = match ($this->component_type) {
            'core' => 'WordPress',
            'theme' => __('the theme :name', ['name' => $this->component_name ?: $this->component_slug]),
            default => __('the plugin :name', ['name' => $this->component_name ?: $this->component_slug]),
        };
        if ($this->fixed_version) {
            return __('Update :what to version :version or newer.', ['what' => $what, 'version' => $this->fixed_version]);
        }
        if ($this->component_type === 'core') {
            return __('No fixed version is available yet. Keep WordPress up to date and watch for a security release.');
        }

        return __('No fixed version is available yet. Deactivate and remove :what, or replace it with an alternative that is maintained.', ['what' => $what]);
    }
}
