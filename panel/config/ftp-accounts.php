<?php

return [
    // Customers add, change and remove extra FTP accounts themselves.
    'customer_manage' => (bool) env('WHP_FTP_CUSTOMER', true),

    // Extra FTP accounts per website when neither the plan (CRM limit
    // "ftp_accounts") nor the subscription sets one. null = unlimited.
    'default_limit' => env('WHP_FTP_DEFAULT_LIMIT') === null || env('WHP_FTP_DEFAULT_LIMIT') === ''
        ? null : (int) env('WHP_FTP_DEFAULT_LIMIT'),
];
