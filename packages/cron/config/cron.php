<?php

return [
    // Customers manage the cron jobs of their own websites (the package must allow at least one).
    'customer_access' => (bool) env('WHP_CRON_CUSTOMER_ACCESS', true),
    // Highest number of cron jobs per website, whatever the package says.
    'max_per_website' => (int) env('WHP_CRON_MAX_PER_WEBSITE', 50),
    // Shortest time between two runs that customers may schedule (minutes). Admins may go to 1.
    'min_interval' => (int) env('WHP_CRON_MIN_INTERVAL', 1),
    // Runs kept per cron job in the panel (the server keeps the last 50).
    'keep_runs' => (int) env('WHP_CRON_KEEP_RUNS', 25),
    // While a job keeps failing, mail again at most this often (hours).
    'remind_hours' => (int) env('WHP_CRON_REMIND_HOURS', 24),
];
