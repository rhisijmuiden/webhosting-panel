<?php

return [
    // Requests per minute per token.
    'rate_limit' => (int) env('WHP_CRM_RATE_LIMIT', 120),
    // How long a one-time login link stays valid, in minutes.
    'login_link_minutes' => 5,
    // Webhook retry delays in seconds after a failed delivery.
    'webhook_retries' => [60, 300, 1800, 7200, 21600, 86400],
    'webhook_timeout' => 10,
    // Default for DELETE /subscriptions/{id}: days the final backup is kept.
    'keep_backup_days' => 30,
];
