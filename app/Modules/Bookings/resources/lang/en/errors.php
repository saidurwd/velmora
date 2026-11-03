<?php

declare(strict_types=1);

return [
    'variant_unknown' => 'That product was not found.', 'product_not_service' => 'Only service products can be booked.', 'service_not_defined' => 'Make this service bookable first.',
    'location_invalid' => 'Choose where the service happens.', 'lead_time_invalid' => 'The soonest booking time must be from 0 to 525,600 minutes.', 'horizon_invalid' => 'How far ahead people may book must be from 1 to 365 days.', 'hold_invalid' => 'A slot may be held for 5 to 120 minutes.',
    'duration_invalid' => 'A service takes from 5 minutes to 24 hours.', 'buffer_invalid' => 'Time kept free before or after must be from 0 to 240 minutes.',
    'name_required' => 'Please give a name.', 'resource_type_invalid' => 'Choose staff, room, vehicle or team.',
    'weekday_invalid' => 'Choose a day of the week.', 'time_invalid' => 'Enter a time like 09:30.', 'date_invalid' => 'Enter a date like 2026-12-25.', 'window_end_before_start' => 'The end must be after the start.',
    'validity_invalid' => 'The “until” date must not be before the “from” date.', 'windows_overlap' => 'Two working times on the same day overlap.',
    'range_invalid' => 'Choose a start day and an end day that is the same or later.', 'range_too_long' => 'Look at at most :days days at a time.', 'package_unavailable' => 'That cannot be booked right now.',
    'slot_unavailable' => 'That time is no longer available. Please choose another.', 'slot_taken' => 'Sorry, somebody just took that time. Please choose another.', 'too_many_holds' => 'You are already holding :max times. Finish one first, or wait a few minutes.',
    'not_for_sale' => 'This service cannot be booked online right now.',
];
