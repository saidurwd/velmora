<?php

declare(strict_types=1);

return [
    // A parcel that fails delivery this many times is raised to a person to be returned to the shop (the first try counts: 3 = two retries).
    'max_delivery_attempts' => (int) env('SHIPPING_MAX_DELIVERY_ATTEMPTS', 3),

    // Customers may send goods back within this many days of the parcel arriving (setting shipping.return_window_days). Staff may start a return at any time.
    'return_window_days' => (int) env('SHIPPING_RETURN_WINDOW_DAYS', 7),

    // How many unfinished parcels one polling run asks the couriers about, and how long a parcel may sit unchanged before it is called stuck (days).
    'poll_batch' => (int) env('SHIPPING_POLL_BATCH', 100),
    'stuck_days' => (int) env('SHIPPING_STUCK_DAYS', 7),
];
