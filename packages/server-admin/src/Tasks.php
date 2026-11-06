<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use Illuminate\Support\Number;

/** Names and descriptions of everything that shows up in the run history. */
final class Tasks
{
    /** English labels, translated through lang/nl.json. */
    public const LABELS = [
        'security_check' => 'Security update check',
        'cleanup' => 'Server cleanup',
        'panel_cleanup' => 'Panel cleanup',
        'db_optimize' => 'Database optimize',
        'health_check' => 'Website health check',
        'php_eol' => 'PHP end of life',
        'upgrade' => 'Install updates',
        'maintenance' => 'Maintenance',
        'php_install' => 'Install PHP version',
        'php_remove' => 'Remove PHP version',
    ];

    public const DESCRIPTIONS = [
        'security_check' => 'Looks for security updates, reads which CVEs they fix and their CVSS score. A critical one is installed straight away when that is turned on.',
        'cleanup' => 'Removes old kernels and unused packages, the package cache, old system logs, old temporary files and PHP sessions of websites and old core dumps.',
        'panel_cleanup' => 'Removes old activity log entries, old run history, failed queue jobs and old panel log files.',
        'db_optimize' => 'Rebuilds database tables with a lot of unused space (OPTIMIZE) and refreshes the index statistics of all databases (ANALYZE).',
        'health_check' => 'Checks every website and service, and mails the administrators when something is down.',
        'php_eol' => 'Warns customers before a PHP version loses security support, moves their websites to the next version afterwards (with a check of every site) and removes the old version.',
    ];

    public const TRIGGERS = ['schedule' => 'Schedule', 'manual' => 'By hand', 'window' => 'Maintenance window', 'critical' => 'Critical CVE', 'eol' => 'PHP end of life'];

    public static function label(string $task): string
    {
        return __(self::LABELS[$task] ?? $task);
    }

    public static function description(string $task): string
    {
        return __(self::DESCRIPTIONS[$task] ?? '');
    }

    public static function trigger(string $trigger): string
    {
        return __(self::TRIGGERS[$trigger] ?? $trigger);
    }

    /** One line about the result of a finished agent job. */
    public static function summary(string $task, ?array $result, ?array $error): string
    {
        if ($error) {
            return (string) ($error['message'] ?? __('Failed'));
        }
        $r = $result ?? [];

        return match ($task) {
            'security_check' => ($r['packages'] ?? []) === []
                ? __('No security updates waiting')
                : __(':n security updates, highest CVSS score :score', ['n' => count($r['packages']), 'score' => $r['max_score'] ?? '?']),
            'cleanup' => __('Freed :size', ['size' => Number::fileSize((int) ($r['freed_bytes'] ?? 0), 1)]),
            'db_optimize' => __(':n tables optimized, :size freed', ['n' => count($r['optimized'] ?? []), 'size' => Number::fileSize((int) ($r['freed_bytes'] ?? 0), 1)]),
            'upgrade' => trim(__('Updates installed.').' '.(($r['reboot_reasons'] ?? []) ? __('A reboot is needed.') : '')),
            'php_install' => __('Installed PHP versions: :list', ['list' => implode(', ', $r['installed'] ?? [])]),
            'php_remove' => __('Installed PHP versions: :list', ['list' => implode(', ', $r['installed'] ?? [])]),
            default => __('Done'),
        };
    }
}
