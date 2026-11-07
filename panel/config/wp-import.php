<?php

// WordPress import wizard (App\Providers\WpImportServiceProvider).
return [
    // Customers may import a WordPress website themselves (admins always can).
    'customer_access' => (bool) env('WHP_WP_IMPORT_CUSTOMER', true),
    // Largest file a customer may upload, per file.
    'max_upload_gb' => (int) env('WHP_WP_IMPORT_MAX_GB', 20),
    // Where uploads wait for the agent; the agent reads the same folder (WHP_WP_IMPORT_UPLOADS).
    'upload_dir' => env('WHP_WP_IMPORT_UPLOADS', '/var/lib/webhosting-panel/wp-import'),
];
