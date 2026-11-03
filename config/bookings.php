<?php

declare(strict_types=1);

return [
    // The one shop timezone. Times are stored in UTC; availability is entered, and slots are shown, in this zone (Bangladesh has no daylight saving).
    'timezone' => 'Asia/Dhaka',

    // Slot start times follow each working window in steps of this many minutes (setting bookings.slot_step_minutes overrides it).
    'slot_step_minutes' => (int) env('BOOKINGS_SLOT_STEP_MINUTES', 15),

    // Once a held time goes to checkout it is kept this long for payment (30 minutes to pay, 5 of margin); the minute sweeper ends it if nobody paid.
    'payment_hold_minutes' => (int) env('BOOKINGS_PAYMENT_HOLD_MINUTES', 35),

    // Customers are reminded this many minutes before the start (24 hours, then 2 hours); settings bookings.reminder_long_minutes / reminder_short_minutes override.
    'reminder_long_minutes' => 1440,
    'reminder_short_minutes' => 120,

    // A running booking nobody completed is completed by the system this many hours after it ended (setting bookings.auto_complete_hours overrides).
    'auto_complete_hours' => 24,

    // A message that fails is tried again on the next run, at most this many times in all.
    'notice_max_attempts' => 3,

    // The review prompt is only sent for bookings completed within this many days (never a flood of old ones).
    'review_prompt_days' => 7,

    // A guest who proved the order's phone may manage that order's bookings for this many minutes.
    'guest_minutes' => 30,

    // One search covers at most this many days and returns at most this many slots, so a page can never ask for a whole year.
    'max_search_days' => 31,
    'max_slots' => 500,
];
