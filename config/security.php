<?php

declare(strict_types=1);

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
            "img-src 'self' data: https:",
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
