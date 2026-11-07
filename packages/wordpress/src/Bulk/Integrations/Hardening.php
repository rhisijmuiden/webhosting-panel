<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use App\Models\ActivityLog;
use App\Services\WpHardening\Hardening as HardeningService;
use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;

/** Switch WordPress hardening measures on or off (wphard.apply). Salts stay out: renewing them logs everyone out. */
final class Hardening extends BaseAction
{
    public function __construct(private readonly HardeningService $hardening) {}

    public function key(): string
    {
        return 'hardening';
    }

    public function label(): string
    {
        return __('Security hardening');
    }

    public function description(): string
    {
        return __('Switch hardening measures on (or off again): file editor off, no PHP in uploads, protect wp-config.php, hide version and user names.');
    }

    public function group(): string
    {
        return 'security';
    }

    public function icon(): string
    {
        return 'lock';
    }

    public function order(): int
    {
        return 5;
    }

    public function concurrency(): int
    {
        return 4;
    }

    public function fields(): array
    {
        $fields = [Field::select('mode', __('What'), ['on' => __('Switch on'), 'off' => __('Switch off')])];
        foreach (HardeningService::definitions() as $key => $d) {
            $fields[] = Field::checkbox($key, __($d['title']), true);
        }

        return $fields;
    }

    public function rules(): array
    {
        return ['mode' => ['required', 'in:on,off']] + array_fill_keys(HardeningService::MEASURES, ['boolean']);
    }

    private function chosen(array $o): array
    {
        return array_values(array_filter(HardeningService::MEASURES, fn ($m) => (bool) ($o[$m] ?? false)));
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        if (! $this->chosen($o)) {
            return __('No measure was chosen.');
        }
        $state = $this->hardening->state($install);
        $want = ($o['mode'] ?? 'on') === 'on';
        if ($state && $state->checked_at?->gt(now()->subMinutes(10))
            && collect($this->chosen($o))->every(fn ($m) => (bool) ($state->measures[$m] ?? ! $want) === $want)) {
            return $want ? __('Already on.') : __('Already off.');
        }

        return null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $measures = array_fill_keys($this->chosen($o), ($o['mode'] ?? 'on') === 'on');
        $status = $this->hardening->apply($install, $measures);
        ActivityLog::record('wp_hardening.applied', $install->label(), $measures, $install->website->customer_id);

        return Step::ok(__(':on of :total measures on.', ['on' => (int) ($status['on'] ?? 0), 'total' => (int) ($status['total'] ?? count(HardeningService::MEASURES))]));
    }

    public function describe(array $o): string
    {
        $titles = HardeningService::definitions();

        return (($o['mode'] ?? 'on') === 'on' ? __('Switch on') : __('Switch off')).': '
            .implode(', ', array_map(fn ($m) => mb_strtolower(__($titles[$m]['title'])), $this->chosen($o)));
    }
}
