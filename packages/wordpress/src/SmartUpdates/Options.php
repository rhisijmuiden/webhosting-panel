<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates;

use App\Models\User;
use App\Services\Settings;

/** Settings of the smart updates (database first, config defaults second). */
final class Options
{
    public const KEYS = ['default_mode', 'threshold', 'visual', 'concurrency', 'customer_policy', 'admin_notify', 'admin_mail_to'];

    public function __construct(private readonly Settings $settings) {}

    public function get(string $key): mixed
    {
        $default = config("wp-smart-updates.$key");
        $value = $this->settings->get("wp_smart.$key");
        if ($value === null) {
            return $default;
        }

        return match (true) {
            is_bool($default) => (bool) $value,
            is_int($default) => (int) $value,
            default => (string) $value,
        };
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set("wp_smart.$key", is_bool($value) ? (int) $value : $value);
    }

    /** @return list<string> */
    public function adminRecipients(): array
    {
        $own = array_filter(array_map('trim', explode(',', (string) $this->get('admin_mail_to'))), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
        if ($own) {
            return array_values($own);
        }

        return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->pluck('email')->filter()->values()->all();
    }
}
