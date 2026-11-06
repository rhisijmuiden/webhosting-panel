<?php

return [
    // Customers manage their own apps (their package must allow at least one app).
    'customer_access' => (bool) env('WHP_WEBAPPS_CUSTOMER_ACCESS', true),
    // Highest number of apps per website, whatever the package says.
    'max_per_website' => (int) env('WHP_WEBAPPS_MAX_PER_WEBSITE', 10),
];
