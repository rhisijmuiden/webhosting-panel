<?php

return [
    // Customers may update, take over WP-cron, change admin passwords and log in to wp-admin.
    'customer_toolkit' => (bool) env('WHP_WP_CUSTOMER_TOOLKIT', true),
    // Daily scan of every website for WordPress installs and outdated parts (server time).
    'scan_time' => env('WHP_WP_SCAN_TIME', '04:15'),
    // WP-cron interval in minutes when the panel takes over WP-cron.
    'cron_intervals' => [1, 5, 15, 30, 60],
];
