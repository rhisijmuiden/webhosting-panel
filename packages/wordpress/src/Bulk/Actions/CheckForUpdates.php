<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Toolkit;

/** Ask every site for its version, plugins, themes and available updates (wp.status). */
final class CheckForUpdates extends BaseAction
{
    public function __construct(private readonly Toolkit $toolkit) {}

    public function key(): string
    {
        return 'check';
    }

    public function label(): string
    {
        return __('Check for updates');
    }

    public function description(): string
    {
        return __('Read the version, plugins, themes and available updates again. Changes nothing on the sites.');
    }

    public function group(): string
    {
        return 'updates';
    }

    public function icon(): string
    {
        return 'search';
    }

    public function order(): int
    {
        return 30;
    }

    public function concurrency(): int
    {
        return 4;
    }

    public function start(WordPressInstall $install, array $options): Step
    {
        $this->toolkit->refresh($install);
        if ($install->last_error) {
            return Step::failed($install->last_error);
        }

        return $install->isOutdated()
            ? Step::ok(trans_choice(':count update available.|:count updates available.', $install->outdatedCount()),
                [__(':p plugins, :t themes', ['p' => $install->outdated_plugins, 't' => $install->outdated_themes])]
                + ($install->core_update ? [1 => __('Core: :v', ['v' => $install->core_update])] : []))
            : Step::ok(__('Up to date (WordPress :v).', ['v' => $install->version ?? '?']));
    }
}
