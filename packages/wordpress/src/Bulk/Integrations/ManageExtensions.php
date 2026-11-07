<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Extensions\ExtensionManager;
use Rhis\WordPress\Models\WordPressInstall;

/** Activate, deactivate or switch auto-updates of named plugins/themes (Extensions, wp.ext_apply). */
class ManageExtensions extends BaseAction
{
    public function __construct(protected readonly ExtensionManager $extensions) {}

    public function key(): string
    {
        return 'plugins-manage';
    }

    public function label(): string
    {
        return __('Switch plugins or themes');
    }

    public function description(): string
    {
        return __('Activate or deactivate named plugins, switch theme, or turn auto-updates on or off. An activation that breaks a site is undone.');
    }

    public function group(): string
    {
        return 'plugins';
    }

    public function icon(): string
    {
        return 'check';
    }

    public function order(): int
    {
        return 10;
    }

    protected function ops(): array
    {
        return [
            'activate' => __('Activate'), 'deactivate' => __('Deactivate (plugins only)'),
            'auto_update_on' => __('Auto-updates on'), 'auto_update_off' => __('Auto-updates off'),
        ];
    }

    public function fields(): array
    {
        return [
            Field::select('type', __('Type'), ['plugin' => __('Plugins'), 'theme' => __('Themes')]),
            Field::select('op', __('What'), $this->ops()),
            Field::text('slugs', __('Slugs'), 'akismet, hello-dolly',
                __('The folder names from wordpress.org, separated by commas. Sites without them are skipped.')),
        ];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:plugin,theme'],
            'op' => ['required', 'in:'.implode(',', array_keys($this->ops()))],
            'slugs' => ['required', 'string', 'max:1000', 'regex:/^\s*[a-z0-9][a-z0-9._-]{0,99}(\s*,\s*[a-z0-9][a-z0-9._-]{0,99})*\s*,?\s*$/'],
        ];
    }

    public static function slugs(array $o): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string) ($o['slugs'] ?? '')))))));
    }

    /** The named slugs this install has. */
    protected function present(WordPressInstall $install, array $o): array
    {
        $list = ($o['type'] ?? 'plugin') === 'theme' ? ($install->themes ?? []) : ($install->plugins ?? []);

        return collect($list)->pluck('name')->intersect(self::slugs($o))->values()->all();
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        $type = $o['type'] ?? 'plugin';
        if ($type === 'theme' && ($o['op'] ?? '') === 'deactivate') {
            return __('Themes cannot be deactivated; activate another theme instead.');
        }
        if ($type === 'theme' && ($o['op'] ?? '') === 'activate' && count(self::slugs($o)) !== 1) {
            return __('Name exactly one theme to activate.');
        }

        return $this->present($install, $o) ? null : __('Not installed on this site.');
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        return $this->outcome($this->extensions->apply($install, (string) $o['type'], (string) $o['op'], $this->present($install, $o)));
    }

    protected function outcome(array $out): Step
    {
        $errors = collect($out['errors'] ?? [])->map(fn ($e) => is_array($e) ? trim(($e['slug'] ?? '').': '.($e['error'] ?? '')) : (string) $e)->values()->all();
        $done = array_values((array) ($out['done'] ?? []));
        if ($out['rolled_back'] ?? false) {
            return Step::failed(__('The site broke after the change and it was undone.'), $errors);
        }
        if ($done === [] && $errors !== []) {
            return Step::failed(implode('; ', array_slice($errors, 0, 3)), $errors);
        }

        $step = __('Done: :list', ['list' => implode(', ', $done)]);

        return $errors ? Step::attention($step, $errors) : Step::ok($step);
    }

    public function describe(array $o): string
    {
        return ($this->ops()[$o['op'] ?? ''] ?? '').': '.implode(', ', self::slugs($o));
    }
}
