<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use App\Services\Settings;

/** Monitoring settings (database first, config defaults second). */
final class Options
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return (array) config('monitoring.defaults');
    }

    public function get(string $key): mixed
    {
        $default = config("monitoring.defaults.$key");
        $value = $this->settings->get("monitoring.$key");
        if ($value === null) {
            return $default;
        }

        return match (true) {
            is_bool($default) => (bool) $value,
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            default => (string) $value,
        };
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set("monitoring.$key", is_bool($value) ? (int) $value : $value, $key === 'heartbeat_url');
    }

    public function state(string $key, mixed $default = null): mixed
    {
        $raw = $this->settings->get("monitoring.state.$key");

        return $raw === null ? $default : json_decode((string) $raw, true);
    }

    public function setState(string $key, mixed $value): void
    {
        $this->settings->set("monitoring.state.$key", json_encode($value));
    }
}
