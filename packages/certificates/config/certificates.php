<?php

return [
    // Customers may upload own certificates, request wildcards and switch back
    // to Let's Encrypt for their own websites (admins always can).
    'customer_manage' => (bool) env('WHP_CERTIFICATES_CUSTOMER_MANAGE', true),

    // Days before expiry on which a warning mail goes out (own certificates).
    'warn_days' => [30, 14, 7, 3, 1],

    // Let's Encrypt renews well before expiry by itself (also the shorter
    // 45-day certificates); warn only when renewals have clearly failed.
    'letsencrypt_warn_days' => [10, 5, 2, 1],

    // Daily check time (server time).
    'check_at' => env('WHP_CERTIFICATES_CHECK_AT', '06:10'),
];
