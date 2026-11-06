<?php

return [
    // Failed panel logins are appended here; CrowdSec tails the file.
    'auth_log' => env('WHP_AUTH_LOG', '/var/log/webhosting-panel/auth.log'),

    // How many addresses a customer may allowlist per website.
    'client_allowlist_max' => 20,
];
