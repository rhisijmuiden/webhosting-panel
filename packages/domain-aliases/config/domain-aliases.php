<?php

return [
    // Customers add and remove aliases themselves in the client panel.
    'customer_manage' => (bool) env('WHP_ALIASES_CUSTOMER', true),

    // Aliases per website when neither the plan (CRM limit "domain_aliases")
    // nor the subscription sets one. null = unlimited.
    'default_limit' => env('WHP_ALIASES_DEFAULT_LIMIT') === null || env('WHP_ALIASES_DEFAULT_LIMIT') === ''
        ? null : (int) env('WHP_ALIASES_DEFAULT_LIMIT'),
];
