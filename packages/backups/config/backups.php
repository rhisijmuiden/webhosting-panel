<?php

return [
    // Customers can restore their own websites from the client panel.
    'customer_restore' => (bool) env('WHP_BACKUP_CUSTOMER_RESTORE', true),
    // Backup location customers restore from. Empty = the first enabled one.
    'customer_target' => env('WHP_BACKUP_CUSTOMER_TARGET'),
];
