<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

final class Severity
{
    public const RANK = ['ok' => 0, 'unknown' => 0, 'warning' => 1, 'critical' => 2];

    public static function rank(?string $status): int
    {
        return self::RANK[$status] ?? 0;
    }

    public static function isProblem(?string $status): bool
    {
        return self::rank($status) > 0;
    }

    public static function worst(iterable $statuses): string
    {
        $worst = 'ok';
        foreach ($statuses as $s) {
            if (self::rank($s) > self::rank($worst)) {
                $worst = $s;
            }
        }

        return $worst;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'critical' => __('Critical'),
            'warning' => __('Warning'),
            'unknown' => __('Unknown'),
            default => __('OK'),
        };
    }

    /** Status for x-ui.pill. */
    public static function pill(string $status): string
    {
        return match ($status) {
            'critical' => 'bad',
            'warning' => 'warn',
            'unknown' => 'neutral',
            default => 'ok',
        };
    }
}
