<?php

return [
    // Defaults of the settings on /admin/monitoring/settings (the database wins).
    'defaults' => [
        'enabled' => true,
        'interval' => 5,              // minutes between two checks
        'fail_after' => 2,            // failed checks in a row before a website or service counts as down
        'slow_ms' => 3000,            // slower answers are shown as slow (no alert)
        'reminder_hours' => 12,       // repeat the alert while a problem stays open (0 = never)
        'disk_warning' => 85,         // percent used
        'disk_critical' => 95,
        'inode_warning' => 90,
        'memory_warning' => 95,
        'load_warning' => 2.0,        // 5-minute load per CPU core
        'mail_queue_warning' => 200,  // messages waiting in the Postfix queue
        'ssl_warning_days' => 14,     // Let's Encrypt renews at 30 days left, so 14 means renewing failed
        'ssl_critical_days' => 3,
        'heartbeat_url' => '',        // pinged after every check (external dead man's switch)
        'customer_alerts' => true,    // customers may get a mail when their website goes down
        'status_page' => true,        // public page at /status
        'status_title' => 'Service status',
    ],

    // Raw check results are kept this many days; daily totals forever.
    'samples_days' => 7,

    // Status page components and the services that make them work.
    'components' => [
        'web' => ['label' => 'Websites', 'services' => ['nginx', 'php*-fpm']],
        'mail' => ['label' => 'Email', 'services' => ['postfix', 'dovecot', 'rspamd']],
        'databases' => ['label' => 'Databases', 'services' => ['mariadb', 'valkey-server']],
        'panel' => ['label' => 'Control panel', 'services' => ['whp-queue']],
    ],
];
