<?php

declare(strict_types=1);

namespace Rhis\Backups;

use Illuminate\Support\Facades\Cache;

/** Remembers the backup jobs started from the panel, so they can be shown and followed. */
final class BackupJobs
{
    private const KEY = 'backups.jobs';

    public static function remember(string $id, string $label, ?int $customerId = null): void
    {
        $jobs = array_filter(Cache::get(self::KEY, []), fn ($j) => $j['id'] !== $id);
        array_unshift($jobs, [
            'id' => $id, 'label' => $label, 'customer_id' => $customerId,
            'user' => auth()->user()?->name, 'started_at' => now()->toIso8601String(),
        ]);
        Cache::forever(self::KEY, array_slice(array_values($jobs), 0, 30));
    }

    public static function find(string $id): ?array
    {
        foreach (Cache::get(self::KEY, []) as $job) {
            if ($job['id'] === $id) {
                return $job;
            }
        }

        return null;
    }

    public static function recent(?int $customerId = null, int $limit = 8): array
    {
        $jobs = Cache::get(self::KEY, []);
        if ($customerId !== null) {
            $jobs = array_filter($jobs, fn ($j) => $j['customer_id'] === $customerId);
        }

        return array_slice(array_values($jobs), 0, $limit);
    }
}
