<?php

declare(strict_types=1);

namespace Rhis\Reports;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Data that other packages add to the monthly report, without depending on this one:
 *
 *   if (class_exists(\Rhis\Reports\Reports::class)) {
 *       \Rhis\Reports\Reports::provide('uptime', fn (Customer $c, CarbonImmutable $from, CarbonImmutable $to) => [...]);
 *   }
 *
 * Known keys:
 * - uptime: null, or ['sites' => [domain => ['uptime' => 99.98, 'downtime_minutes' => 9, 'incidents' => 1, 'response_ms' => 230|null]]]
 */
final class Reports
{
    /** @var array<string, callable> */
    private static array $providers = [];

    public static function provide(string $key, callable $provider): void
    {
        self::$providers[$key] = $provider;
    }

    public static function has(string $key): bool
    {
        return isset(self::$providers[$key]);
    }

    /** The provider's answer, or null when there is none or it failed (a report never breaks on one section). */
    public static function get(string $key, Customer $customer, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! isset(self::$providers[$key])) {
            return null;
        }
        try {
            $data = (self::$providers[$key])($customer, $from, $to);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    public static function forget(string $key): void
    {
        unset(self::$providers[$key]);
    }
}
