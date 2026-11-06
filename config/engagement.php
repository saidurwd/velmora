<?php

declare(strict_types=1);

return [
    // How many items one customer can keep on their wishlist (soft by the few requests that can arrive at the same instant).
    'wishlist_max' => 200,

    // How many back-in-stock alerts one customer can have waiting.
    'alerts_max' => 100,

    // How many days after buying a customer can still review it.
    'review_window_days' => 180,

    // Reminders to signed-in customers who left items in their cart. Marketing: sent only to people who opted in, never in their quiet hours. Off until the shop turns it on.
    'cart_recovery' => [
        'enabled' => (bool) env('CART_RECOVERY_ENABLED', false),
        'idle_hours' => 2,          // nothing in the cart changed for this long
        'max_age_days' => 3,        // carts untouched for longer are left alone
        'min_gap_days' => 7,        // at least this long between two reminders to the same customer
        'per_run' => 200,           // at most this many reminders in one run
        'page' => 500,              // carts read at a time (up to 10 pages a run), so people who said no cannot hide the ones who said yes
    ],

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
