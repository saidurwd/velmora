<?php

declare(strict_types=1);

return [
    // How many items one customer can keep on their wishlist (soft by the few requests that can arrive at the same instant).
    'wishlist_max' => 200,

    // How many back-in-stock alerts one customer can have waiting.
    'alerts_max' => 100,

    // How many days after buying a customer can still review it.
    'review_window_days' => 180,

    'support' => [
        // The private disk where files sent with a message are kept.
        'disk' => env('SUPPORT_DISK', 'private'),
        'max_open_tickets' => 5,
        'attachments_per_message' => 3,
        'attachment_max_bytes' => 5_242_880,
        'body_max' => 5000,
        // Settings support.first_response_hours / support.reopen_days override these.
        'first_response_hours' => 24,
        'reopen_days' => 14,
    ],
];
