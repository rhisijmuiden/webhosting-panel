<?php

return [
    // Customers may link repositories to their own websites.
    'customer_access' => (bool) env('WHP_GIT_CUSTOMER_ACCESS', true),
    // Customers may add their own deploy commands (they run as the website's own Linux user).
    'customer_commands' => (bool) env('WHP_GIT_CUSTOMER_COMMANDS', true),
    // Repositories per website.
    'max_per_website' => (int) env('WHP_GIT_MAX_PER_WEBSITE', 5),
    // Releases kept in zero-downtime mode (for rollback).
    'keep_releases' => (int) env('WHP_GIT_KEEP_RELEASES', 5),
    // Deploy log lines kept per deployment, and deployments kept per repository.
    'keep_deployments' => (int) env('WHP_GIT_KEEP_DEPLOYMENTS', 50),
    // Build steps a repository can switch on, in the order they run.
    'steps' => ['composer', 'npm', 'artisan_migrate', 'artisan_optimize'],
];
