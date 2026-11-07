<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Switches\Switches;

/** Page cache, object cache, cache plugins and expired transients in one go (wp.cache_clear). */
final class ClearCaches extends BaseAction
{
    public function __construct(private readonly Switches $switches) {}

    public function key(): string
    {
        return 'clear-cache';
    }

    public function label(): string
    {
        return __('Clear all caches');
    }

    public function description(): string
    {
        return __('Empty the page cache, the object cache, cache plugins (WP Rocket, W3TC, LiteSpeed …) and expired transients.');
    }

    public function group(): string
    {
        return 'maintenance';
    }

    public function icon(): string
    {
        return 'bolt';
    }

    public function order(): int
    {
        return 20;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $out = $this->switches->clearCache($install);
        $lines = collect($out['steps'] ?? [])->map(fn ($s) => is_array($s)
            ? trim(($s['label'] ?? $s['name'] ?? $s['step'] ?? '').': '.(($s['ok'] ?? true) ? __('ok') : ($s['error'] ?? __('failed'))))
            : (string) $s)->values()->all();

        return ($out['ok'] ?? true) ? Step::ok(__('Caches cleared.'), $lines) : Step::attention(__('Some caches could not be cleared.'), $lines);
    }
}
