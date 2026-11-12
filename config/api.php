<?php

declare(strict_types=1);

return [
    /*
    | The oldest app build the server still serves, per platform (API_ARCHITECTURE §3). An app that sends X-App-Platform and an
    | X-App-Version below this gets 426 app.upgrade_required. A request without those headers (a browser, Bruno, curl) is not gated.
    | `latest` is only advice shown in /config so an app can offer an update.
    */
    'app' => [
        'min_supported_version' => ['android' => env('API_MIN_VERSION_ANDROID', '1.0.0'), 'ios' => env('API_MIN_VERSION_IOS', '1.0.0')],
        'latest_version' => ['android' => env('API_LATEST_VERSION_ANDROID', '1.0.0'), 'ios' => env('API_LATEST_VERSION_IOS', '1.0.0')],
        'store_urls' => ['android' => env('API_STORE_URL_ANDROID'), 'ios' => env('API_STORE_URL_IOS')],
    ],

    // Sign-in tokens (ADR P11-2): a short access token and a longer refresh token that is swapped for a new pair every time it is used.
    'tokens' => ['access_minutes' => 60, 'refresh_days' => 30, 'reauth_minutes' => 10, 'max_sessions_per_user' => 20],

    // How long a client may reuse a catalog/config read without asking again (Cache-Control max-age). The ETag still lets it ask cheaply.
    'cache_seconds' => ['config' => 300, 'catalog' => 60],
];
