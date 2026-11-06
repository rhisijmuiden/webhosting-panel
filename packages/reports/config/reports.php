<?php

return [
    // Defaults; admins change these under Tools & settings > Monthly reports (stored in the settings table).
    'defaults' => [
        'enabled' => true,
        // Day of the month the report about the previous month is sent (1-28), and the time (panel time zone).
        'day' => 1,
        'time' => '09:00',
        'bcc' => null,
        'support_email' => null,
        // New customers get the report unless they (or an admin) switch it off.
        'default_on' => true,
    ],
];
