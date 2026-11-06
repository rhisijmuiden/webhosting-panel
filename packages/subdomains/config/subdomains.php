<?php

return [
    // Customers add and remove subdomains of their own domains themselves.
    'customer_manage' => (bool) env('WHP_SUBDOMAINS_CUSTOMER', true),

    // Subdomains per domain (null = unlimited). A subdomain is part of the
    // domain's subscription: it is not a hosting package of its own.
    'max_per_domain' => env('WHP_SUBDOMAINS_MAX') !== null ? (int) env('WHP_SUBDOMAINS_MAX') : null,

    // Customers clone their websites (to a test subdomain or another website of theirs).
    'customer_clone' => (bool) env('WHP_SITE_CLONE_CUSTOMER', true),

    // Names that are never a customer's website: mail, DNS and panel services use them.
    'reserved' => ['www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'webmail', 'autodiscover', 'autoconfig', 'mta-sts',
        'ns1', 'ns2', 'ns', 'mx', 'ftp', 'cpanel', 'panel', 'admin', 'localhost', 'wpad', 'isatap'],
];
