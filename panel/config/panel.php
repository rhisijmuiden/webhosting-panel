<?php

return [
    // Release channel: production follows the main branch, test the test branch.
    'channel' => env('WHP_CHANNEL', 'production'),

    'channels' => [
        'production' => 'main',
        'test' => 'test',
    ],

    // socket (default, the real agent) or fake (local development and tests)
    'agent_driver' => env('WHP_AGENT_DRIVER', 'socket'),
    'agent_socket' => env('WHP_AGENT_SOCKET', '/run/whp-agent/agent.sock'),

    'version_file' => env('WHP_VERSION_FILE', base_path('../VERSION')),

    // Admins must set up two-factor authentication before they can use the panel.
    'require_admin_2fa' => (bool) env('WHP_REQUIRE_ADMIN_2FA', true),

    'brand' => [
        'name' => env('APP_NAME', 'Webhosting Panel'),
        'product' => 'Hosting',
    ],
];
