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

    // The brand, in one place. App\Support\Brand reads it; views get $brandName and $provider.
    'brand' => [
        // The software.
        'name' => 'Dekwerk',
        // Label next to the logo in the admin menu.
        'product' => 'Hosting',
        // The hosting company customers deal with. Admins can change it under
        // Tools & settings > Branding; this is the default.
        'provider' => env('WHP_PROVIDER_NAME', env('WHP_BRAND', 'RHIS')),
        // Paths under public/.
        'logo' => 'brand/logo-wit.svg',
        'logo_dark' => 'brand/logo.svg',
        'icon' => 'brand/icon.svg',
        'favicon' => 'brand/favicon.svg',
    ],
];
