<?php

return [
    // New WordPress installs send their mail through this server automatically
    // (not when an SMTP plugin is active, and never on a staging copy).
    'auto' => (bool) env('WHP_WP_MAIL_AUTO', true),
    // Sender of a website when nobody chose one: <local part>@<website domain>.
    'local_part' => env('WHP_WP_MAIL_FROM', 'noreply'),
    // Customers may change the sender and send test messages.
    'customer' => (bool) env('WHP_WP_MAIL_CUSTOMER', true),
    // Plugins that send mail their own way. While one is active the install is
    // not switched over automatically (it may use Microsoft 365 or a mail service on purpose).
    'smtp_plugins' => [
        'wp-mail-smtp', 'wp-mail-smtp-pro', 'post-smtp', 'postman-smtp', 'fluent-smtp', 'easy-wp-smtp', 'smtp-mailer',
        'wp-smtp', 'gmail-smtp', 'mailgun', 'sendgrid-email-delivery-simplified', 'wp-ses', 'wp-offload-ses',
        'offload-ses-lite', 'sparkpost', 'smtp2go', 'suremails', 'brevo', 'mailin', 'mailjet-for-wordpress',
        'postmark-approved-wordpress-plugin', 'wp-mail-bank', 'mail-bank', 'site-mailer',
    ],
];
