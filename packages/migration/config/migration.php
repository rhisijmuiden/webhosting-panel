<?php

return [
    // How many domains are copied at the same time. Each one runs rsync and a
    // database dump against the source server.
    'parallel' => (int) env('WHP_MIGRATION_PARALLEL', 2),

    // Lowest PHP version the panel offers; older Plesk sites are moved up to it.
    'min_php' => '8.3',
];
