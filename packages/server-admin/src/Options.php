<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Carbon;

/** Settings of server management (database first, config defaults second). */
final class Options
{
    public const KEYS = [
        'window_enabled', 'window_days', 'window_start', 'window_end', 'window_install', 'window_reboot', 'mail_mode', 'mail_to',
        'critical_enabled', 'critical_threshold', 'critical_reboot', 'critical_reboot_delay', 'nvd_api_key',
        'php_eol_enabled', 'php_notice_days', 'php_grace_days', 'php_remove_old', 'php_notify_customers',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function get(string $key): mixed
    {
        $default = config("server-admin.defaults.$key");
        $value = $this->settings->get("server.$key");
        if ($value === null) {
            return $default;
        }

        return match (true) {
            is_bool($default) => (bool) $value,
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            is_array($default) => json_decode((string) $value, true) ?: [],
            default => (string) $value,
        };
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set("server.$key", is_array($value) ? json_encode(array_values($value)) : (is_bool($value) ? (int) $value : $value),
            $key === 'nvd_api_key');
    }

    /** Free-form state (last scan, handled CVEs, ...), stored as JSON. */
    public function state(string $key, mixed $default = null): mixed
    {
        $raw = $this->settings->get("server.state.$key");

        return $raw === null ? $default : json_decode((string) $raw, true);
    }

    public function setState(string $key, mixed $value): void
    {
        $value === null ? $this->settings->forget("server.state.$key") : $this->settings->set("server.state.$key", json_encode($value));
    }

    public function timezone(): string
    {
        return (string) config('server-admin.timezone') ?: 'UTC';
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $own = array_filter(array_map('trim', explode(',', (string) $this->get('mail_to'))), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
        if ($own) {
            return array_values($own);
        }

        return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->pluck('email')->filter()->values()->all();
    }

    /**
     * The window that contains $at, as [start, end], or null.
     * A window that ends before it starts runs past midnight (23:00-01:00).
     */
    public function windowAt(Carbon $at): ?array
    {
        if (! $this->get('window_enabled')) {
            return null;
        }
        $days = array_map('intval', (array) $this->get('window_days'));
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->get('window_start')) + [0, 0]);
        [$eh, $em] = array_map('intval', explode(':', (string) $this->get('window_end')) + [0, 0]);
        foreach ([0, 1] as $back) {
            $start = $at->copy()->subDays($back)->setTime($sh, $sm);
            $end = $start->copy()->setTime($eh, $em);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            if (in_array($start->dayOfWeek, $days, true) && $at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return [$start, $end];
            }
        }

        return null;
    }

    /** The next window start after $from (for the screens). */
    public function nextWindow(?Carbon $from = null): ?Carbon
    {
        if (! $this->get('window_enabled') || ! $this->get('window_days')) {
            return null;
        }
        $from ??= $this->now();
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->get('window_start')) + [0, 0]);
        for ($i = 0; $i <= 7; $i++) {
            $start = $from->copy()->addDays($i)->setTime($sh, $sm);
            if ($start->greaterThan($from) && in_array($start->dayOfWeek, array_map('intval', (array) $this->get('window_days')), true)) {
                return $start;
            }
        }

        return null;
    }
}
