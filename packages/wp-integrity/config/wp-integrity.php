<?php

return [
    // Customers see the integrity check of their own WordPress sites and may restore files.
    'customer' => (bool) env('WHP_WP_INTEGRITY_CUSTOMER', true),

    // Nightly check, after the malware scan (03:30) and the WordPress scan (04:15),
    // before the security scan (05:15) that mails customers.
    'check_at' => env('WHP_WP_INTEGRITY_AT', '04:45'),

    // Report changed files as findings in the Security center and the customer's security alerts.
    'findings' => (bool) env('WHP_WP_INTEGRITY_FINDINGS', true),
];
