<?php

declare(strict_types=1);

return [
    // Days start and end at midnight in this zone (stored times are UTC).
    'timezone' => env('REPORTING_TIMEZONE', 'Asia/Dhaka'),

    // The only currency the reports add up (the shop sells in one).
    'currency' => 'BDT',

    // Every 15 minutes the latest days are worked out again; every night the last `window_days` are (late cancellations and refunds land on the day they belong to).
    // Older days change only when staff run `php artisan reports:rebuild`.
    'recent_days' => 2,
    'window_days' => 35,

    // The longest period one screen or export may cover, and the longest a rebuild may cover.
    'max_range_days' => 366,
    'max_rebuild_days' => 800,
];
