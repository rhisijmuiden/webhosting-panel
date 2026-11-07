<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Toolkit;

/** Let the server run WP-cron (a systemd timer) or give it back to WordPress (wp.cron_set). */
final class SetWpCron extends BaseAction
{
    public function __construct(private readonly Toolkit $toolkit) {}

    public function key(): string
    {
        return 'wp-cron';
    }

    public function label(): string
    {
        return __('Set WP-cron');
    }

    public function description(): string
    {
        return __('Let the server run WP-cron on a fixed schedule (faster pages, reliable scheduled posts), or give it back to WordPress.');
    }

    public function group(): string
    {
        return 'maintenance';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function order(): int
    {
        return 10;
    }

    public function concurrency(): int
    {
        return 5;
    }

    public function fields(): array
    {
        $intervals = collect(config('wordpress.cron_intervals', [5]))
            ->mapWithKeys(fn ($n) => [$n => trans_choice(':count minute|:count minutes', $n)])->all();

        return [
            Field::select('mode', __('Who runs WP-cron'), ['system' => __('The server'), 'wordpress' => __('WordPress itself')]),
            Field::select('interval', __('Every'), $intervals, in_array(5, config('wordpress.cron_intervals', [5])) ? 5 : null,
                __('Only used when the server runs WP-cron.')),
        ];
    }

    public function rules(): array
    {
        return ['mode' => ['required', 'in:system,wordpress'], 'interval' => ['nullable', 'integer', 'in:'.implode(',', config('wordpress.cron_intervals', [5]))]];
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        $interval = (int) ($o['interval'] ?? 5);
        if (($o['mode'] ?? '') === 'wordpress' && $install->cron_mode !== 'system') {
            return __('WordPress already runs its own cron.');
        }
        if (($o['mode'] ?? '') === 'system' && $install->cron_mode === 'system' && (int) $install->cron_interval === $interval) {
            return __('Already set like this.');
        }

        return null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $interval = (int) ($o['interval'] ?? 5);
        $this->toolkit->setCron($install, (string) $o['mode'], $interval);

        return Step::ok($o['mode'] === 'system'
            ? __('The server now runs WP-cron every :n minutes.', ['n' => $interval])
            : __('WordPress runs its own cron again.'));
    }

    public function describe(array $o): string
    {
        return ($o['mode'] ?? '') === 'system'
            ? __('The server, every :n minutes', ['n' => (int) ($o['interval'] ?? 5)])
            : __('WordPress itself');
    }
}
