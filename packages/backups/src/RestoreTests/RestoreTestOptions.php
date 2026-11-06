<?php

declare(strict_types=1);

namespace Rhis\Backups\RestoreTests;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Carbon;

/** Settings of the automatic restore tests (stored in the panel's settings table). */
final class RestoreTestOptions
{
    public const PREFIX = 'backups.restore_test.';

    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public const DEFAULTS = [
        'enabled' => '1',
        'frequency' => 'weekly',   // daily, weekly, monthly
        'day' => 'Wed',            // weekly
        'monthday' => '1',         // monthly
        'time' => '06:00',         // after the nightly backups (02:30, full Sunday 03:30)
        'target' => '',            // '' = every enabled location
        'system' => '1',
        'sites' => '2',
        'mail' => '1',
        'databases' => '3',
        'kinds' => 'both',         // latest, both (also the newest full restore point)
        'max_age_hours' => '36',
        'notify' => 'problems',    // problems, warnings, always, never
        'mail_to' => '',           // '' = every active administrator
    ];

    public function __construct(private readonly Settings $settings) {}

    public function get(string $key): string
    {
        return (string) $this->settings->get(self::PREFIX.$key, self::DEFAULTS[$key] ?? '');
    }

    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $this->settings->set(self::PREFIX.$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }
    }

    public function enabled(): bool
    {
        return $this->get('enabled') === '1';
    }

    /** Parameters for the agent's backup.verify. */
    public function agentParams(?string $target = null): array
    {
        return array_filter([
            'target' => $target ?? ($this->get('target') ?: null),
            'system' => $this->get('system') === '1',
            'sites' => (int) $this->get('sites'),
            'mail' => (int) $this->get('mail'),
            'databases' => (int) $this->get('databases'),
            'kinds' => $this->get('kinds'),
            'max_age_hours' => (int) $this->get('max_age_hours'),
        ], fn ($v) => $v !== null);
    }

    /** The newest scheduled moment at or before $now. */
    public function lastSlot(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy();
        [$h, $m] = array_map('intval', explode(':', $this->get('time') ?: '06:00'));
        $slot = $now->copy()->setTime($h, $m);
        switch ($this->get('frequency')) {
            case 'daily':
                if ($slot->gt($now)) {
                    $slot->subDay();
                }
                break;
            case 'monthly':
                $day = max(1, min(28, (int) $this->get('monthday')));
                $slot->setDay($day);
                if ($slot->gt($now)) {
                    $slot->subMonthNoOverflow()->setDay($day);
                }
                break;
            default:
                $dow = array_search($this->get('day'), self::DAYS, true);
                $dow = $dow === false ? 2 : $dow; // Carbon: Monday = 1 ... Sunday = 0 (7)
                $target = ($dow + 1) % 7;
                while ($slot->dayOfWeek !== $target || $slot->gt($now)) {
                    $slot->subDay();
                }
        }

        return $slot;
    }

    public function nextSlot(?Carbon $now = null): ?Carbon
    {
        if (! $this->enabled()) {
            return null;
        }
        $now = $now ?? now();
        $last = $this->lastSlot($now);

        return match ($this->get('frequency')) {
            'daily' => $last->copy()->addDay(),
            'monthly' => $last->copy()->addMonthNoOverflow(),
            default => $last->copy()->addWeek(),
        };
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $own = array_filter(array_map('trim', explode(',', $this->get('mail_to'))), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
        if ($own) {
            return array_values($own);
        }

        return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->pluck('email')->filter()->values()->all();
    }
}
