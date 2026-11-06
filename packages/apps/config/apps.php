<?php

/*
 * The app catalog as shown in the panel. The agent decides what can really be
 * installed (apps.catalog); an app that is here but not in the agent is hidden.
 * Texts are English and translated through lang/nl.json.
 */
return [
    'catalog' => [
        'wordpress' => [
            'name' => 'WordPress',
            'category' => 'Website & blog',
            'description' => 'The most used website builder in the world. Ready with Dutch or English language, nice URLs and safe defaults.',
            'monogram' => 'W',
            'color' => '#21759b',
            'subdir' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://wordpress.org',
        ],
        'joomla' => [
            'name' => 'Joomla',
            'category' => 'Website & CMS',
            'description' => 'Flexible content management system for larger websites with many pages and users.',
            'monogram' => 'J',
            'color' => '#f44321',
            'subdir' => true,
            'locales' => ['en'],
            'homepage' => 'https://www.joomla.org',
        ],
        'nextcloud' => [
            'name' => 'Nextcloud',
            'category' => 'Files & collaboration',
            'description' => 'Your own cloud for files, calendars and contacts. Installed in the root of the website, page cache is switched off.',
            'monogram' => 'N',
            'color' => '#0082c9',
            'subdir' => false,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://nextcloud.com',
        ],
    ],

    // Customers may install apps themselves from the client panel.
    'customer_install' => (bool) env('WHP_APPS_CUSTOMER_INSTALL', true),
];
