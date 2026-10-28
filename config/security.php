<?php

declare(strict_types=1);

return [
    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        // Report-only until Phase 12 hardening; then flip to true (and tighten script-src).
        'enforce' => (bool) env('CSP_ENFORCE', false),
        'policy' => implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]),
    ],
];
