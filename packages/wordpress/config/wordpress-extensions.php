<?php

return [
    // Customers may activate, deactivate, delete and install plugins and themes and switch auto-updates.
    'customer_access' => (bool) env('WHP_WP_CUSTOMER_EXTENSIONS', true),
    // Search results from WordPress.org are kept this many minutes.
    'search_cache_minutes' => 30,
    // Quick searches shown on the "Add new" tab.
    'suggestions' => ['plugin' => ['SEO', 'Contact form', 'WooCommerce', 'Translation', 'Gallery'], 'theme' => ['Business', 'Blog', 'Portfolio', 'Block theme']],
];
