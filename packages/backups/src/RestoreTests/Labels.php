<?php

declare(strict_types=1);

namespace Rhis\Backups\RestoreTests;

/** Human labels for the restore test report (scopes, checks, kinds). */
final class Labels
{
    public static function scope(string $s): string
    {
        return match (true) {
            $s === 'system' => __('Server configuration'),
            str_starts_with($s, 'site:') => __('Website :name', ['name' => substr($s, 5)]),
            str_starts_with($s, 'mail:') => __('Mail :name', ['name' => substr($s, 5)]),
            str_starts_with($s, 'db:') => __('Database :name', ['name' => substr($s, 3)]),
            default => $s,
        };
    }

    public static function icon(string $s): string
    {
        return match (true) {
            $s === 'system' => 'server', str_starts_with($s, 'site:') => 'globe',
            str_starts_with($s, 'mail:') => 'mail', str_starts_with($s, 'db:') => 'database', default => 'backup',
        };
    }

    public static function check(string $c): string
    {
        return match ($c) {
            'listing' => __('File list readable'),
            'disk_space' => __('Free disk space'),
            'restore' => __('Downloaded, decrypted and verified'),
            'complete' => __('Complete'),
            'symlinks' => __('Symbolic links'),
            'php_syntax' => __('PHP files parse'),
            'wordpress' => __('WordPress configuration'),
            'mailboxes' => __('Mailboxes'),
            'configuration' => __('Configuration folders'),
            'dump_complete' => __('Dump is complete'),
            'import' => __('Imports in a test database'),
            'tables' => __('Tables pass CHECK TABLE'),
            default => $c,
        };
    }

    public static function kind(?string $k): string
    {
        return match ($k) {
            'full' => __('Full'), 'incremental' => __('Incremental'), default => (string) $k,
        };
    }

    public static function itemStatus(?string $s): string
    {
        return match ($s) {
            'ok' => __('Passed'), 'warning' => __('Warning'), 'failed' => __('Failed'), 'skipped' => __('Skipped'), default => (string) $s,
        };
    }

    public static function tone(?string $s): string
    {
        return match ($s) {
            'ok' => 'ok', 'warning', 'skipped' => 'warn', 'failed' => 'bad', default => 'neutral',
        };
    }

    /** Bytes for people (1.4 GB). */
    public static function bytes(?int $b): string
    {
        if ($b === null) {
            return '–';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            $i++;
        }

        return round($v, $i ? 1 : 0).' '.$u[$i];
    }
}
