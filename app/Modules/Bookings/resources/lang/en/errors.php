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
    'not_found' => 'That booking was not found.', 'not_cancellable' => 'This booking can no longer be cancelled.', 'already_started' => 'This booking has already started.', 'reason_required' => 'Please give a reason.',
    'waive_staff_only' => 'Only staff can waive the fee.', 'not_reschedulable' => 'This booking can no longer be moved.', 'reschedule_too_late' => 'It is too late to move this booking. It can be moved at least :hours hours before it starts.',
    'reschedule_limit' => 'This booking has already been moved :max times, which is the most allowed.', 'same_time' => 'That is the time it already has.', 'no_show_too_early' => 'A no-show can be recorded only once the booking has started.',
    'not_completable' => 'This booking cannot be marked as done.', 'not_started' => 'This booking has not started yet.',
    'resource_invalid' => 'That person or room cannot do this service.',
    'policy_unknown' => 'That cancellation policy was not found.', 'meeting_url_invalid' => 'Enter a web address that starts with https://.', 'fee_invalid' => 'A fee must be from 0% to 100%.',
    'windows_invalid' => 'Each time limit must be a different whole number of hours, from 1 to 8760.', 'fees_not_rising' => 'Cancelling closer to the start must never cost less: each limit must keep at least as much as the ones further away, the late fee at least as much as the last limit, and a no-show at least as much as the late fee.',
    'move_rules_invalid' => 'Hours before a move must be 0 to 8760, and the number of moves 0 to 20.', 'words_required' => 'Please write the English title and text.', 'default_required' => 'Make another policy the default instead; the shop must always have one.',
    'not_for_sale' => 'This service cannot be booked online right now.',
];
