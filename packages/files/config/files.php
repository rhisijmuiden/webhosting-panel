<?php

return [
    // Customers get the file manager in their panel (admins always have it).
    'enabled' => (bool) env('WHP_FILE_MANAGER', true),
    // Web terminal for customers whose hosting package allows SSH (needs ttyd and bubblewrap on the server).
    'terminal' => (bool) env('WHP_WEB_TERMINAL', true),
    // Minutes a terminal session may stay open.
    'terminal_minutes' => (int) env('WHP_WEB_TERMINAL_MINUTES', 60),
    // Spool folder shared with whp-agent (owner whp, 0700). File contents pass through it.
    'spool_dir' => env('WHP_FILES_SPOOL', '/var/lib/webhosting-panel/files-spool'),
    // Uploads are sent in chunks of this size, so PHP's upload limits do not matter.
    'chunk_mb' => 8,
    // Largest single upload in MB.
    'max_upload_mb' => (int) env('WHP_FILES_MAX_UPLOAD_MB', 4096),
    // Largest file the code editor opens (the agent refuses more than 5 MB too).
    'editor_max_kb' => 5120,
];
