<?php

/*
 * The app catalog as shown in the panel. The agent decides what can really be
 * installed (apps.catalog); an app that is here but not in the agent is hidden,
 * and so is an app the agent reports as no longer maintained.
 * Texts are English and translated through lang/nl.json.
 *
 * database: the app gets its own MariaDB database (counts for the package limit).
 * admin_user_hint: shown under the username field when the app is stricter than usual.
 */
return [
    'categories' => [
        'websites' => 'Websites',
        'shop' => 'Webshop',
        'photos' => 'Photos',
        'wiki' => 'Wiki & knowledge',
        'community' => 'Community',
        'files' => 'Files & collaboration',
        'reading' => 'News & reading',
    ],

    'catalog' => [
        'wordpress' => [
            'name' => 'WordPress',
            'category' => 'websites',
            'description' => 'The most used website builder in the world. Ready with Dutch or English language, nice URLs and safe defaults.',
            'monogram' => 'W',
            'color' => '#21759b',
            'subdir' => true,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://wordpress.org',
        ],
        'woocommerce' => [
            'name' => 'WooCommerce',
            'category' => 'shop',
            'description' => 'A complete webshop on WordPress: products, cart, checkout and orders. In Dutch it starts with euros and the Netherlands as shop country.',
            'monogram' => 'Wc',
            'color' => '#7f54b3',
            'subdir' => true,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://woocommerce.com',
        ],
        'joomla' => [
            'name' => 'Joomla',
            'category' => 'websites',
            'description' => 'Flexible content management system for larger websites with many pages and users.',
            'monogram' => 'J',
            'color' => '#f44321',
            'subdir' => true,
            'database' => true,
            'locales' => ['en'],
            'homepage' => 'https://www.joomla.org',
        ],
        'piwigo' => [
            'name' => 'Piwigo',
            'category' => 'photos',
            'description' => 'Photo albums on your own website: upload, sort into albums, share with visitors or only with chosen people.',
            'monogram' => 'P',
            'color' => '#ff7700',
            'subdir' => true,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://piwigo.org',
        ],
        'mediawiki' => [
            'name' => 'MediaWiki',
            'category' => 'wiki',
            'description' => 'The wiki software behind Wikipedia, for a large knowledge base or documentation that many people work on.',
            'monogram' => 'M',
            'color' => '#36c',
            'subdir' => true,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://www.mediawiki.org',
            'admin_user_hint' => 'Letters, digits, dots, - and _ (no @).',
        ],
        'dokuwiki' => [
            'name' => 'DokuWiki',
            'category' => 'wiki',
            'description' => 'A light wiki for manuals and team notes. Keeps its pages in files, so it needs no database.',
            'monogram' => 'D',
            'color' => '#2b73b7',
            'subdir' => true,
            'database' => false,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://www.dokuwiki.org',
            'admin_user_hint' => 'Lowercase letters, digits, dots, - and _.',
        ],
        'phpbb' => [
            'name' => 'phpBB',
            'category' => 'community',
            'description' => 'A classic discussion forum with topics, private messages and moderators. New members are confirmed by e-mail.',
            'monogram' => 'Bb',
            'color' => '#105289',
            'subdir' => true,
            'database' => true,
            'locales' => ['en'],
            'homepage' => 'https://www.phpbb.com',
            'admin_user_hint' => '3 to 20 characters.',
        ],
        'nextcloud' => [
            'name' => 'Nextcloud',
            'category' => 'files',
            'description' => 'Your own cloud for files, calendars and contacts. Installed in the root of the website, page cache is switched off.',
            'monogram' => 'N',
            'color' => '#0082c9',
            'subdir' => false,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://nextcloud.com',
        ],
        'freshrss' => [
            'name' => 'FreshRSS',
            'category' => 'reading',
            'description' => 'Follow news sites and blogs in one place with your own feed reader. New articles are fetched every 20 minutes.',
            'monogram' => 'F',
            'color' => '#0062be',
            'subdir' => true,
            'database' => true,
            'locales' => ['en', 'nl'],
            'homepage' => 'https://freshrss.org',
            'admin_user_hint' => '3 to 16 letters, digits or _.',
        ],
    ],

    // Customers may install apps themselves from the client panel.
    'customer_install' => (bool) env('WHP_APPS_CUSTOMER_INSTALL', true),

    // Security: an app whose project published no release in this many days (or whose
    // version is end of life) is not offered any more. Installed copies keep working.
    'max_release_age_days' => (int) env('WHP_APPS_MAX_RELEASE_AGE_DAYS', 365),
];
