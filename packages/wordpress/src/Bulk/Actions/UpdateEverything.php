<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Toolkit;

/** Update core, plugins and themes, with a snapshot and automatic rollback (wp.update). */
class UpdateEverything extends BaseAction
{
    public function __construct(protected readonly Toolkit $toolkit) {}

    public function key(): string
    {
        return 'update';
    }

    public function label(): string
    {
        return __('Update WordPress');
    }

    public function description(): string
    {
        return __('Update core, plugins and themes. A snapshot is taken first and a site that breaks is rolled back.');
    }

    public function group(): string
    {
        return 'updates';
    }

    public function icon(): string
    {
        return 'refresh';
    }

    public function order(): int
    {
        return 10;
    }

    public function fields(): array
    {
        return [
            Field::checkbox('core', __('WordPress core'), true),
            Field::checkbox('plugins', __('Plugins'), true),
            Field::checkbox('themes', __('Themes'), true),
            Field::checkbox('snapshot', __('Snapshot first and roll back when the site breaks'), true,
                __('Strongly advised. Without it an update that breaks a site has to be fixed by hand.')),
            Field::checkbox('only_outdated', __('Skip sites that are already up to date'), true),
        ];
    }

    public function rules(): array
    {
        return ['core' => ['boolean'], 'plugins' => ['boolean'], 'themes' => ['boolean'], 'snapshot' => ['boolean'], 'only_outdated' => ['boolean']];
    }

    public function concurrency(): int
    {
        return 2;
    }

    public function timeout(): int
    {
        return 2 * 3600;
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        if (! ($o['core'] ?? true) && ! ($o['plugins'] ?? true) && ! ($o['themes'] ?? true)) {
            return __('Nothing was chosen to update.');
        }
        if (! ($o['only_outdated'] ?? true) || ! $install->checked_at) {
            return null;
        }
        $todo = (($o['core'] ?? true) && $install->core_update)
            || (($o['plugins'] ?? true) && $install->outdated_plugins > 0)
            || (($o['themes'] ?? true) && $install->outdated_themes > 0);

        return $todo ? null : __('Already up to date.');
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $core = (bool) ($o['core'] ?? true);
        $plugins = ($o['plugins'] ?? true) ? 'all' : false;
        $themes = ($o['themes'] ?? true) ? 'all' : false;

        return Step::wait($this->toolkit->update($install, $core, $plugins, $themes, (bool) ($o['snapshot'] ?? true)));
    }

    /** Toolkit::follow() stores the outcome on the install (last_update_*); we report from there. */
    public function finish(WordPressInstall $install, array $o, array $state): Step
    {
        $job = $this->toolkit->job((string) ($state['id'] ?? '')) ? (string) $state['id'] : null;
        if ($job) {
            $this->toolkit->follow($job);
        }
        $install->refresh();
        $s = $install->last_update_summary ?? [];
        $lines = array_values(array_filter([
            ! empty($s['core']) ? __('Core: :v', ['v' => is_array($s['core']) ? implode(' → ', $s['core']) : $s['core']]) : null,
            ! empty($s['plugins']) ? __('Plugins: :list', ['list' => self::names($s['plugins'])]) : null,
            ! empty($s['themes']) ? __('Themes: :list', ['list' => self::names($s['themes'])]) : null,
            ...array_map(fn ($e) => (string) $e, array_slice($s['errors'] ?? [], 0, 5)),
        ]));

        return match ($install->last_update_status) {
            'succeeded' => Step::ok(self::count($s) ? trans_choice(':count item updated.|:count items updated.', self::count($s)) : __('Nothing needed an update.'), $lines),
            'rolled_back' => Step::failed(__('The site broke after the update and was rolled back to the snapshot.'), $lines),
            default => Step::failed((string) ($state['error']['message'] ?? __('The update failed.')), $lines),
        };
    }

    public function describe(array $o): string
    {
        $parts = array_filter([
            ($o['core'] ?? true) ? __('core') : null,
            ($o['plugins'] ?? true) ? __('plugins') : null,
            ($o['themes'] ?? true) ? __('themes') : null,
        ]);

        return implode(', ', $parts).(($o['snapshot'] ?? true) ? '' : ' · '.__('without snapshot'));
    }

    protected static function names(mixed $list): string
    {
        return collect((array) $list)->map(fn ($p) => is_array($p) ? ($p['name'] ?? $p['slug'] ?? '').(isset($p['new_version']) ? ' '.$p['new_version'] : (isset($p['version']) ? ' '.$p['version'] : '')) : (string) $p)
            ->filter()->take(20)->implode(', ');
    }

    protected static function count(array $s): int
    {
        return (! empty($s['core']) ? 1 : 0) + count((array) ($s['plugins'] ?? [])) + count((array) ($s['themes'] ?? []));
    }
}
