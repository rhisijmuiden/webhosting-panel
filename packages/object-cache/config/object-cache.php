<?php

return [
    // Customers switch the object cache of their own websites on and off.
    'customer_access' => (bool) env('WHP_OBJECT_CACHE_CUSTOMER_ACCESS', true),
    // Memory of one object cache when the package says "Server default" (MB).
    'default_mb' => (int) env('WHP_OBJECT_CACHE_DEFAULT_MB', 64),
];
