<?php

declare(strict_types=1);

return [
    'title' => 'Integrations',
    'intro' => 'Payment, SMS and email credentials. Secrets are never shown again after saving. Changing anything asks for your own password.',
    'server' => [
        'sandbox' => 'Payment sandbox (set by the server)', 'live_allowed' => 'Live payments allowed (set by the server)', 'sms_driver' => 'SMS driver (set by the server)', 'mailer' => 'Mail driver (set by the server)',
        'on' => 'On', 'off' => 'Off',
        'note' => 'These switches stay with the server. Only credentials can be changed here.',
    ],
    'providers' => [
        'sslcommerz' => ['title' => 'SSLCommerz (online payment)', 'about' => 'Store credentials for card, mobile wallet and bank payments.', 'test_note' => 'Asks the sandbox about a made-up transaction. Never used in live mode.'],
        'sms' => ['title' => 'SMS (SSL Wireless)', 'about' => 'Token and sender id for text messages.', 'test_note' => 'Checks the format only. Nothing is sent.'],
        'mail' => ['title' => 'Email (SMTP)', 'about' => 'The mail server that sends order and account emails.', 'test_note' => 'Connects and signs in. Nothing is sent.'],
    ],
    'fields' => [
        'sslcommerz' => ['store_id' => 'Store ID', 'store_password' => 'Store password'],
        'sms' => ['api_token' => 'API token', 'sid' => 'Sender ID'],
        'mail' => ['host' => 'Host', 'port' => 'Port', 'scheme' => 'Connection type', 'username' => 'Username', 'password' => 'Password', 'from_address' => 'From address', 'from_name' => 'From name'],
    ],
    'source' => ['saved' => 'Saved here.', 'server' => 'Using the server setting.', 'none' => 'Not set.', 'unreadable' => 'A saved value cannot be read (the app key changed). Enter it again.'],
    'secret_help' => 'Leave blank to keep it.',
    'remove' => 'Remove the saved value and use the server setting',
    'your_password' => 'Your password',
    'save' => 'Save',
    'test' => 'Test connection',
    'saved' => 'Saved.',
    'nothing_changed' => 'Nothing was changed.',
    'tests' => [
        'sslcommerz_accepted' => 'SSLCommerz accepted the credentials.', 'sslcommerz_rejected' => 'SSLCommerz rejected the store ID or password.', 'sslcommerz_inactive' => 'The SSLCommerz store is not active.',
        'sslcommerz_invalid' => 'SSLCommerz did not accept the request.', 'sslcommerz_unreachable' => 'Could not reach SSLCommerz. Try again later.', 'sslcommerz_not_set' => 'Store ID and password are not set.',
        'sslcommerz_live_untouched' => 'The server is in live mode, so no test was made against the real bank.',
        'sms_not_set' => 'Token and sender ID are not set.', 'sms_format_ok' => 'The token and sender ID look right. Nothing was sent.', 'sms_format_bad' => 'The token or sender ID has a wrong format.',
        'mail_not_set' => 'Host is not set.', 'mail_host_blocked' => 'That host is not allowed.', 'mail_failed' => 'Could not sign in to the mail server.', 'mail_ok' => 'Signed in to the mail server. Nothing was sent.',
    ],
    'errors' => ['host_blocked' => 'That host is not allowed.', 'port_not_allowed' => 'Use port 25, 465, 587 or 2525.'],
];
