<?php

declare(strict_types=1);

namespace Rhis\Reports;

use App\Services\Settings;

/** Monthly report settings (database first, config defaults second). */
final class Options
{
    public function __construct(private readonly Settings $settings) {}

    public function get(string $key): mixed
    {
        $default = config("reports.defaults.$key");
        $value = $this->settings->get("reports.$key");
        if ($value === null) {
            return $default;
        }

        return match (true) {
            is_bool($default) => (bool) $value,
            is_int($default) => (int) $value,
            default => (string) $value === '' ? null : (string) $value,
        };
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set("reports.$key", is_bool($value) ? (int) $value : $value);
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function day(): int
    {
        return max(1, min(28, (int) $this->get('day')));
    }

    public function time(): string
    {
        $t = (string) $this->get('time');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '09:00';
    }

    public function bcc(): ?string
    {
        $v = $this->get('bcc');

        return $v && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
    }

    public function supportEmail(): ?string
    {
        $v = $this->get('support_email') ?: config('mail.from.address');

        return $v && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
    }

    public function defaultOn(): bool
    {
        return (bool) $this->get('default_on');
    }
}
