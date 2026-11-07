<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use App\Services\Agent\AgentException;
use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Extensions\ExtensionManager;
use Rhis\WordPress\Models\WordPressInstall;

/** Install one plugin or theme from wordpress.org on every selected site (wp.ext_install). */
final class InstallExtension extends BaseAction
{
    public function __construct(private readonly ExtensionManager $extensions) {}

    public function key(): string
    {
        return 'plugins-install';
    }

    public function label(): string
    {
        return __('Install a plugin or theme');
    }

    public function description(): string
    {
        return __('Install one plugin or theme from wordpress.org, optionally switched on. Sites that already have it are skipped.');
    }

    public function group(): string
    {
        return 'plugins';
    }

    public function icon(): string
    {
        return 'plus';
    }

    public function order(): int
    {
        return 20;
    }

    public function fields(): array
    {
        return [
            Field::select('type', __('Type'), ['plugin' => __('Plugin'), 'theme' => __('Theme')]),
            Field::text('slug', __('Slug'), 'wordfence', __('The folder name from wordpress.org.')),
            Field::checkbox('activate', __('Switch it on after installing'), true),
        ];
    }

    public function rules(): array
    {
        return ['type' => ['required', 'in:plugin,theme'], 'slug' => ['required', 'string', 'regex:'.ExtensionManager::SLUG], 'activate' => ['boolean']];
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        $list = ($o['type'] ?? 'plugin') === 'theme' ? ($install->themes ?? []) : ($install->plugins ?? []);

        return collect($list)->pluck('name')->contains($o['slug'] ?? '') ? __('Already installed.') : null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        try {
            $out = $this->extensions->install($install, (string) $o['type'], (string) $o['slug'], (bool) ($o['activate'] ?? false));
        } catch (AgentException $e) {
            if ($e->errorCode === 'conflict') {
                return Step::skipped(__('Already installed.'));
            }
            throw $e;
        }
        if ($out['rolled_back'] ?? false) {
            return Step::attention(__('Installed, but switching it on broke the site, so it was switched off again.'));
        }

        return Step::ok(($out['activated'] ?? false) ? __('Installed and switched on.') : __('Installed.'));
    }

    public function describe(array $o): string
    {
        return ($o['slug'] ?? '').(($o['activate'] ?? false) ? ' · '.__('switched on') : '');
    }
}
