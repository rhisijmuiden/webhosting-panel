<?php

return [
    // Debug mode switches itself off after one of these numbers of hours (the first is the default).
    'debug_hours' => [24, 1, 4, 72],
    // Admins may also leave debug mode on until they switch it off themselves.
    'debug_forever_admin' => true,
    // How long the stored state of the switches is shown before it is read again from WordPress (minutes).
    'stale_minutes' => 10,
];
