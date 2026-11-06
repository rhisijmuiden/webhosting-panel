<?php

return [
    // Customers can restore their own websites from the nightly server backups.
    'customer_restore' => (bool) env('WHP_BACKUP_CUSTOMER_RESTORE', true),
    // Backup location customers restore from. Empty = the first enabled one.
    'customer_target' => env('WHP_BACKUP_CUSTOMER_TARGET'),

    // Customers can set up their own backup locations (FTP, FTPS, SFTP/SCP).
    'customer_targets' => (bool) env('WHP_BACKUP_CUSTOMER_TARGETS', true),
    // ... and restore from an uploaded backup zip.
    'customer_upload_restore' => (bool) env('WHP_BACKUP_CUSTOMER_UPLOAD', true),
    'client_max_targets' => (int) env('WHP_BACKUP_CUSTOMER_MAX_TARGETS', 5),
    'client_max_upload_gb' => (int) env('WHP_BACKUP_CUSTOMER_MAX_UPLOAD_GB', 20),
    // Scheduled customer backups that may run at the same time.
    'client_parallel' => (int) env('WHP_BACKUP_CUSTOMER_PARALLEL', 3),
    // Where uploads wait for the agent; the agent reads the same folder (WHP_UPLOAD_DIR).
    'upload_dir' => env('WHP_UPLOAD_DIR', '/var/lib/webhosting-panel/uploads'),
];
