<?php

declare(strict_types=1);

use App\Modules\Platform\Support\TrustedProxies;

/*
 * Which callers may tell us the visitor's real address (X-Forwarded-For). Read from a CONFIG file on purpose: env() in bootstrap/app.php runs before Laravel has loaded
 * the .env file for a web request, so a TRUSTED_PROXIES line in .env was silently ignored (only a real server environment variable worked). Config files are read after.
 * Empty = nobody; `*` = whoever calls (only when the app is reachable ONLY through the load balancer); or a comma-separated list of addresses / ranges.
 */
return [
    'proxies' => TrustedProxies::parse(env('TRUSTED_PROXIES')),
];
