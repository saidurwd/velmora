<?php

declare(strict_types=1);

return [
    // How long an online payment may stay unfinished before the order's held stock goes back (minutes).
    'expiry_minutes' => (int) env('PAYMENTS_EXPIRY_MINUTES', 30),

    // The scheduled check asks the gateway about attempts still unfinished after this many minutes; one still open this many hours after its time limit is raised to finance.
    'reconcile_after_minutes' => (int) env('PAYMENTS_RECONCILE_AFTER_MINUTES', 10),
    'long_pending_hours' => (int) env('PAYMENTS_LONG_PENDING_HOURS', 2),

    // A refund above this amount (minor units) needs a second person to approve it. Editable later in the admin panel (setting payments.refund_second_approver_minor).
    'refund_second_approver_minor' => (int) env('PAYMENTS_REFUND_SECOND_APPROVER_MINOR', 500_000),

    // The real (live) gateway is reachable only when ALL of these hold: APP_ENV=production, PAYMENT_SSLCOMMERZ_SANDBOX=false and this switch. Go-live is a human decision.
    'allow_live' => (bool) env('PAYMENTS_ALLOW_LIVE', false),

    // SSLCommerz credentials: environment only, never in the repository. Sandbox host unless PAYMENT_SSLCOMMERZ_SANDBOX=false.
    'sslcommerz' => [
        'store_id' => env('PAYMENT_SSLCOMMERZ_STORE_ID'),
        'store_password' => env('PAYMENT_SSLCOMMERZ_STORE_PASSWORD'),
        'sandbox' => (bool) env('PAYMENT_SSLCOMMERZ_SANDBOX', true),
    ],
];
