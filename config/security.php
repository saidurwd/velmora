<?php

declare(strict_types=1);

// Images come from 'self' or any https: address. A developer's media store is usually plain http on another port (the local S3 at http://127.0.0.1:9010,
// or APP_URL with MEDIA_DRIVER=local), which that rule blocks, so every product and banner image is missing on a dev site. Outside production that one
// address is allowed too; production never gets an http image source.
$devMediaOrigin = (function (): ?string {
    if (env('APP_ENV') === 'production') {
        return null;
    }
    $url = (string) (env('MEDIA_DRIVER', 's3') === 'local' ? env('APP_URL', '') : (env('MEDIA_PUBLIC_URL') ?: env('AWS_ENDPOINT', '')));
    $parts = parse_url($url);
    if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || ! isset($parts['host']) || ! preg_match('/^[A-Za-z0-9.\-]+$/', $parts['host'])) {
        return null;
    }

    return 'http://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
})();

return [
    // Sign-in, sign-up, password reset and similar doors: tries per minute from one network address. Production keeps 10; only the browser tests raise it, because
    // every one of their sign-ins comes from the same address (see e2e/prepare.sh).
    'auth_throttle_per_minute' => (int) env('AUTH_THROTTLE_PER_MINUTE', 10),

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        // Enforced (Phase 12). No page may rely on inline JavaScript: use data attributes + resources/js/behaviors.js (an architecture test checks the views).
        // Set CSP_ENFORCE=false only to diagnose a violation; it reports instead of blocking.
        'enforce' => (bool) env('CSP_ENFORCE', true),
        'policy' => implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: https:".($devMediaOrigin === null ? '' : ' '.$devMediaOrigin),
            "font-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            // No 'unsafe-eval' and no 'unsafe-inline': Alpine runs as its CSP build (its own safe interpreter, no eval), see resources/js/app.js.
            "script-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            // the checkout form posts here and is then redirected to the payment gateway's page; browsers check that redirect against form-action
            "form-action 'self' https://securepay.sslcommerz.com https://sandbox.sslcommerz.com",
            "object-src 'none'",
        ]),
    ],
];
