<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\Switches\Switches;

/** Maintenance mode, debug mode or search engine indexing on/off (Switches, wp.*_set). */
final class QuickSwitch extends BaseAction
{
    public function __construct(private readonly Switches $switches, private readonly string $switch) {}

    public function key(): string
    {
        return 'switch-'.$this->switch;
    }

    public function label(): string
    {
        return match ($this->switch) {
            'maintenance' => __('Maintenance mode'),
            'debug' => __('Debug mode'),
            default => __('Search engine indexing'),
        };
    }

    public function description(): string
    {
        return match ($this->switch) {
            'maintenance' => __('Show visitors a maintenance page (503, rankings are kept); logged-in editors still see the site.'),
            'debug' => __('Write PHP and WordPress errors to the debug log, outside the website. Switches itself off again.'),
            default => __('Let search engines index the sites, or ask them not to.'),
        };
    }

    public function group(): string
    {
        return 'maintenance';
    }

    public function icon(): string
    {
        return match ($this->switch) {
            'maintenance' => 'settings',
            'debug' => 'code',
            default => 'search',
        };
    }

    public function order(): int
    {
        return ['maintenance' => 40, 'debug' => 41, 'indexing' => 42][$this->switch] ?? 45;
    }

    public function concurrency(): int
    {
        return 4;
    }

    public function fields(): array
    {
        $fields = [Field::select('enabled', __('Set to'), ['1' => __('On'), '0' => __('Off')])];
        if ($this->switch === 'maintenance') {
            $fields[] = Field::select('lang', __('Language of the page'), ['en' => 'English', 'nl' => 'Nederlands']);
            $fields[] = Field::text('title', __('Title'), __('We will be back soon'), __('Leave empty for the default text.'));
            $fields[] = Field::text('message', __('Message'), null, __('Leave empty for the default text.'));
        }
        if ($this->switch === 'debug') {
            $fields[] = Field::select('hours', __('Switch off after'), [1 => trans_choice(':count hour|:count hours', 1),
                4 => trans_choice(':count hour|:count hours', 4), 24 => trans_choice(':count hour|:count hours', 24),
                72 => trans_choice(':count hour|:count hours', 72)], 24);
        }

        return $fields;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'in:0,1'],
            'lang' => ['nullable', 'in:en,nl'],
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'hours' => ['nullable', 'integer', 'in:1,4,24,72'],
        ];
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        $state = $this->switches->stored($install);
        $on = filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // Only skip when we know for sure (stored state of this switch); debug always runs to reset its timer.
        if ($this->switch !== 'debug' && $state->exists && ! $state->isStale() && (bool) $state->getAttribute($this->switch) === $on) {
            return $on ? __('Already on.') : __('Already off.');
        }

        return null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $on = filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->switches->set($install, $this->switch, $on, array_filter([
            'title' => $o['title'] ?? null, 'message' => $o['message'] ?? null, 'lang' => $o['lang'] ?? null,
            'hours' => $this->switch === 'debug' ? (int) ($o['hours'] ?? 24) : null,
        ], fn ($v) => $v !== null && $v !== ''));

        return Step::ok($on ? __('Switched on.') : __('Switched off.'));
    }

    public function describe(array $o): string
    {
        $on = filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return ($on ? __('On') : __('Off'))
            .($this->switch === 'debug' && $on ? ' · '.trans_choice(':count hour|:count hours', (int) ($o['hours'] ?? 24)) : '');
    }
}
