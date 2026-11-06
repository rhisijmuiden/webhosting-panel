<?php

// Defaults; the admin changes these on the Security overview > Settings screen (stored in settings).
return [
    // wpvulnerability (free, no key) or wordfence (Wordfence Intelligence feed, free API key).
    'source' => env('WHP_VULN_SOURCE', 'wpvulnerability'),
    // Mail customers about what was found.
    'mail_enabled' => (bool) env('WHP_VULN_MAIL', true),
    // Lowest severity that is mailed: critical, high, medium, low. Malware is always mailed.
    'min_severity' => env('WHP_VULN_MIN_SEVERITY', 'medium'),
    // Remind the customer after this many days while the problem is still there, at most max_reminders times.
    'reminder_days' => (int) env('WHP_VULN_REMINDER_DAYS', 7),
    'max_reminders' => (int) env('WHP_VULN_MAX_REMINDERS', 3),
    // Daily schedule (server time): feed download, then the scan after the WordPress scan (04:15).
    'sync_time' => env('WHP_VULN_SYNC_TIME', '03:45'),
    'scan_time' => env('WHP_VULN_SCAN_TIME', '05:15'),
    // Support address shown in the mail when customers cannot update themselves.
    'support_email' => env('WHP_SUPPORT_EMAIL'),
];
