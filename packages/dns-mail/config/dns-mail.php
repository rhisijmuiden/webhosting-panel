<?php

return [
    // Cloudflare API token with Zone:Read and DNS:Edit. The admin screen
    // stores it encrypted in the settings table; this env value is the fallback.
    'cloudflare_token' => env('CLOUDFLARE_API_TOKEN'),

    // Account new zones are added to by the DNS wizard. Empty: the only
    // account the token can see. The token then also needs Zone: Edit.
    'cloudflare_account_id' => env('CLOUDFLARE_ACCOUNT_ID'),

    'server' => [
        'mail_host' => env('WHP_MAIL_HOST'),
        'ipv4' => env('WHP_PUBLIC_IPV4'),
        'ipv6' => env('WHP_PUBLIC_IPV6'),
        // SPF includes of the outbound relay, if Postfix uses one. Comma separated.
        'relay_spf_includes' => array_filter(explode(',', (string) env('WHP_RELAY_SPF_INCLUDES', ''))),
        'webmail_host' => env('WHP_WEBMAIL_HOST'),
        'brand' => env('WHP_BRAND', 'RHIS'),
    ],

    'defaults' => [
        'dmarc_policy' => 'quarantine',
        'dmarc_rua' => env('WHP_DMARC_RUA'),
        'tls_rpt' => env('WHP_TLSRPT_RUA'),
        'mta_sts_mode' => 'testing',
        'spf_all' => '~',
        'proxied' => true,
    ],
];
