<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;

/** Update one or a few named plugins or themes everywhere they are installed (for example after a security alert). */
final class UpdateComponents extends UpdateEverything
{
    public function key(): string
    {
        return 'update-plugins';
    }

    public function label(): string
    {
        return __('Update specific plugins or themes');
    }

    public function description(): string
    {
        return __('Update only the plugins or themes you name, on every selected site that has them. Handy after a security alert.');
    }

    public function group(): string
    {
        return 'updates';
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function order(): int
    {
        return 20;
    }

    public function fields(): array
    {
        return [
            Field::select('type', __('Type'), ['plugin' => __('Plugins'), 'theme' => __('Themes')]),
            Field::text('slugs', __('Slugs'), 'woocommerce, contact-form-7',
                __('The folder names from wordpress.org, separated by commas. Sites without them are skipped.')),
            Field::checkbox('snapshot', __('Snapshot first and roll back when the site breaks'), true),
        ];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:plugin,theme'],
            'slugs' => ['required', 'string', 'max:1000', 'regex:/^\s*[a-z0-9][a-z0-9._-]{0,99}(\s*,\s*[a-z0-9][a-z0-9._-]{0,99})*\s*,?\s*$/'],
            'snapshot' => ['boolean'],
        ];
    }

    public static function slugs(array $o): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string) ($o['slugs'] ?? '')))))));
    }

    /** The named slugs this install has, with an update available. */
    private function due(WordPressInstall $install, array $o): array
    {
        $list = ($o['type'] ?? 'plugin') === 'theme' ? ($install->themes ?? []) : ($install->plugins ?? []);
        $want = self::slugs($o);

        return collect($list)->filter(fn ($p) => in_array($p['name'] ?? '', $want, true) && ($p['update'] ?? false))
            ->pluck('name')->values()->all();
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        $list = ($o['type'] ?? 'plugin') === 'theme' ? ($install->themes ?? []) : ($install->plugins ?? []);
        $has = collect($list)->pluck('name')->intersect(self::slugs($o));
        if ($has->isEmpty()) {
            return __('Not installed on this site.');
        }

        return $this->due($install, $o) ? null : __('Already up to date.');
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $slugs = $this->due($install, $o);
        $theme = ($o['type'] ?? 'plugin') === 'theme';

        return Step::wait($this->toolkit->update($install, false, $theme ? false : $slugs, $theme ? $slugs : false, (bool) ($o['snapshot'] ?? true)));
    }

    public function describe(array $o): string
    {
        return (($o['type'] ?? 'plugin') === 'theme' ? __('Themes') : __('Plugins')).': '.implode(', ', self::slugs($o));
    }
}
