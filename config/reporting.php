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

    'exports' => [
        // The private disk where finished files are kept (random names, never in the web root).
        'disk' => env('REPORT_EXPORT_DISK', 'private'),
        'expires_days' => 3,        // a finished file is deleted after this
        'link_minutes' => 30,       // a download link on the "My exports" page works for this long (and needs the staff member signed in)
        'max_rows' => 200_000,      // an export with more rows than this is refused
        'open_max' => 3,            // exports one person may have waiting or running at a time
        'stale_minutes' => 30,      // an export still "running" after this is marked failed (the worker died)
    ],

    // The screen shows this many rows of a report; the export has all of them.
    'screen_rows' => 500,
];
