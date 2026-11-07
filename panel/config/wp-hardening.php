<?php

return [
    // Customers switch the security measures of their own WordPress sites on and off.
    'customer_access' => (bool) env('WHP_WP_HARDENING_CUSTOMER', true),
];
