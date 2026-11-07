<?php

return [
    // Customers (and team members with "Website settings") see the logs of their own websites.
    'customer_access' => (bool) env('WHP_SITE_LOGS_CUSTOMER', true),

    // Seconds between two live tail requests in the browser.
    'tail_interval' => max(2, (int) env('WHP_SITE_LOGS_TAIL_INTERVAL', 3)),
];
