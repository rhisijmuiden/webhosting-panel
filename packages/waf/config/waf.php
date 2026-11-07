<?php

return [
    // Mode of sites without their own choice, until the admin picks another one.
    // Detect only first: matches are logged, nothing is blocked, so false alarms
    // can be fixed with an exception before blocking starts.
    'default_mode' => env('WHP_WAF_DEFAULT_MODE', 'detect'),
    // Days that attack events are kept.
    'keep_days' => (int) env('WHP_WAF_KEEP_DAYS', 30),
    // Exceptions per website.
    'max_site_exceptions' => 50,
];
