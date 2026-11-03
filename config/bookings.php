<?php

declare(strict_types=1);

return [
    // The one shop timezone. Times are stored in UTC; availability is entered, and slots are shown, in this zone (Bangladesh has no daylight saving).
    'timezone' => 'Asia/Dhaka',

    // Slot start times follow each working window in steps of this many minutes (setting bookings.slot_step_minutes overrides it).
    'slot_step_minutes' => (int) env('BOOKINGS_SLOT_STEP_MINUTES', 15),

    // One search covers at most this many days and returns at most this many slots, so a page can never ask for a whole year.
    'max_search_days' => 31,
    'max_slots' => 500,
];
