<?php

// Smart updates: scheduled WordPress updates in the maintenance window, checked with
// screenshots. These are defaults; the admin changes them on WordPress > Smart updates > Settings.
return [
    // Mode for installs that have no own choice: all | security | notify | off.
    'default_mode' => env('WHP_WP_SMART_DEFAULT', 'security'),
    // Percentage of the page that may look different after an update before it is rolled back.
    'threshold' => (int) env('WHP_WP_SMART_THRESHOLD', 10),
    // Compare screenshots (needs Chromium on the server; without it only the health check guards).
    'visual' => (bool) env('WHP_WP_SMART_VISUAL', true),
    // How many installs are updated at the same time.
    'concurrency' => (int) env('WHP_WP_SMART_CONCURRENCY', 2),
    // Used when server management has no maintenance window (or is not installed), server time.
    'window' => ['start' => env('WHP_WP_SMART_START', '02:00'), 'end' => env('WHP_WP_SMART_END', '05:00')],
    // No new update starts this many minutes before the window closes.
    'stop_before_end' => 15,
    // Customers may choose the mode, pages and threshold of their own sites.
    'customer_policy' => (bool) env('WHP_WP_SMART_CUSTOMER', true),
    // Mail the admins when an update was rolled back or failed (addresses; empty = all admins).
    'admin_notify' => (bool) env('WHP_WP_SMART_ADMIN_NOTIFY', true),
    'admin_mail_to' => env('WHP_WP_SMART_ADMIN_MAIL', ''),
    // History and screenshots are kept this many days.
    'keep_days' => (int) env('WHP_WP_SMART_KEEP_DAYS', 90),
    // A job that does not finish within this many minutes is marked as failed.
    'job_timeout' => 180,
];
