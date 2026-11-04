<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | fake | sslwireless
        // SSL Wireless ISMS Plus (docs/vendor/sslwireless/). The token and sender id come from the provider's portal; this server's IP address must be whitelisted there.
        'sslwireless' => [
            'base_url' => env('SSLW_SMS_BASE_URL', 'https://smsplus.sslwireless.com/api/v3'),
            'api_token' => env('SSLW_SMS_API_TOKEN'),
            'sid' => env('SSLW_SMS_SID'),
        ],
    ],

];
