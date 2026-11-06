<?php

return [
    // Times of the maintenance window and the tasks are in the server's own time zone.
    'timezone' => env('APP_TIMEZONE', 'Europe/Amsterdam') ?: 'Europe/Amsterdam',

    // End of security support per PHP version (php.net/supported-versions).
    // After this date (plus the grace period in the settings) websites move to
    // the next supported version that is installed on the server.
    'php_eol' => [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ],

    // Defaults of the settings screen.
    'defaults' => [
        'window_enabled' => true,
        'window_days' => [0, 1, 2, 3, 4, 5, 6], // 0 = Sunday
        'window_start' => '03:00',
        'window_end' => '04:00',
        'window_install' => 'security', // security | all
        'window_reboot' => true,
        'mail_mode' => 'reboots', // always | reboots | problems
        'critical_enabled' => true,
        'critical_threshold' => 9.0,
        'critical_reboot' => 'soon', // soon | window
        'critical_reboot_delay' => 15, // minutes
        'php_eol_enabled' => true,
        'php_notice_days' => 30,
        'php_grace_days' => 0,
        'php_remove_old' => true,
        'php_notify_customers' => true,
    ],

    // Scheduled maintenance tasks: defaults (the admin changes them in the panel).
    'tasks' => [
        'security_check' => ['frequency' => 'hourly', 'time' => '00:17', 'enabled' => true],
        'cleanup' => ['frequency' => 'daily', 'time' => '04:15', 'enabled' => true,
            'options' => ['journal_days' => 30, 'site_tmp_days' => 7, 'site_sessions_days' => 2, 'jobs_days' => 30, 'apt' => true]],
        'panel_cleanup' => ['frequency' => 'daily', 'time' => '04:25', 'enabled' => true,
            'options' => ['activity_days' => 365, 'runs_days' => 90, 'failed_jobs_days' => 30, 'log_days' => 14]],
        'db_optimize' => ['frequency' => 'weekly', 'time' => '04:40', 'weekday' => 0, 'enabled' => true,
            'options' => ['min_free_mb' => 50, 'analyze' => true]],
        'health_check' => ['frequency' => 'hourly', 'time' => '00:05', 'enabled' => true],
        'php_eol' => ['frequency' => 'daily', 'time' => '09:00', 'enabled' => true],
    ],
];
