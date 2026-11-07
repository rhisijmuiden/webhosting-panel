<?php

return [
    // Customers see the explorer in their panel (admins always do).
    'customer' => (bool) env('WHP_DISK_USAGE_CUSTOMER', true),

    // The nightly scan refreshes every website whose last scan is older than this.
    'max_age_hours' => (int) env('WHP_DISK_USAGE_MAX_AGE_HOURS', 20),

    // Time of the nightly scan (server time). The scan runs with idle I/O priority.
    'scan_at' => env('WHP_DISK_USAGE_SCAN_AT', '03:40'),

    // A website at or above this share of its disk quota is marked as nearly full.
    'warn_percent' => (int) env('WHP_DISK_USAGE_WARN_PERCENT', 85),
];
