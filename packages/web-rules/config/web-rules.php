<?php

return [
    // Customers manage redirects and access rules of their own websites.
    'customer_access' => (bool) env('WHP_WEB_RULES_CUSTOMER', true),

    // Limits per website (the agent refuses more as well).
    'max_redirects' => 200,
    'max_folders' => 20,
    'max_users' => 50,
    'max_networks' => 200,
];
