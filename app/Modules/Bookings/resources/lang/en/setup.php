<?php

declare(strict_types=1);

return [
    'nav' => 'Booking setup', 'title' => 'Booking setup', 'tab' => ['services' => 'Services', 'resources' => 'People and rooms', 'holidays' => 'Holidays', 'policies' => 'Cancellation policies'],
    'services_help' => 'A service is a product of the type "service". Open it by its code to say how long it takes, where it happens and how far ahead people may book.', 'add_by_sku' => 'Service code (SKU)', 'open' => 'Open',
    'no_services' => 'No service is bookable yet.', 'service' => 'Service', 'where' => 'Where', 'duration' => 'Takes', 'policy' => 'Cancellation policy', 'state' => 'State', 'default_policy' => 'The shop\'s default', 'on' => 'On', 'off' => 'Off',
    'not_yet' => 'This product is not bookable yet. Fill this in and save to make it bookable.', 'duration_min' => 'Takes (minutes)', 'buffer_before' => 'Kept free before (minutes)', 'buffer_after' => 'Kept free after (minutes)',
    'lead_time' => 'Soonest booking (minutes from now)', 'horizon' => 'How many days ahead', 'hold' => 'Time held while paying (minutes)', 'the_default' => 'default',
    'meeting_url' => 'Meeting link (online services)', 'meeting_help' => 'Only people who booked see it. It must start with https://.', 'bookable' => 'Can be booked', 'package_on' => 'This time and price can be chosen',
    'save' => 'Save', 'saved' => 'Saved.', 'removed' => 'Removed.', 'new_resource' => 'Add a person or room', 'no_resources' => 'There are no people or rooms yet.', 'name' => 'Name', 'type' => 'Kind', 'does' => 'Does these services',
    'types' => ['staff' => 'Person', 'room' => 'Room', 'vehicle' => 'Vehicle', 'team' => 'Team'],
    'weekly' => 'Weekly hours', 'weekly_help' => 'Times like 09:00 and 17:00, in Dhaka time. Leave a row empty to skip it. A row with dates is a seasonal timetable. Saving replaces all the hours.', 'day' => 'Day',
    'days' => ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'],
    'from_time' => 'From', 'to_time' => 'To', 'valid_from' => 'From date', 'valid_to' => 'To date', 'save_hours' => 'Save hours',
    'exceptions' => 'Days off and one-off hours', 'exceptions_help' => 'For one date: closed all day, or different hours than usual.', 'closed' => 'Closed', 'remove' => 'Remove', 'note' => 'Note', 'add' => 'Add',
    'holidays_help' => 'On a holiday nobody can be booked, whoever works.', 'no_holidays' => 'No holidays coming up.',
    'new_policy' => 'New cancellation policy', 'edit_policy' => 'Cancellation policy', 'no_policies' => 'There is no cancellation policy yet.', 'late_fee' => 'Fee if cancelled closer than that (%)', 'no_show_fee' => 'Fee if they do not turn up (%)', 'used_by' => 'Services using it',
    'policy_help' => 'Fees are a percentage of the price of that booking. Closer to the start must never cost less. Bookings already confirmed keep the terms they were made under.',
    'windows' => 'Time limits', 'windows_help' => 'Cancelling at least this many hours before the start keeps this fee. Leave a row empty to skip it.', 'hours_before' => 'Hours before', 'fee_percent' => 'Fee (%)',
    'move_hours' => 'A customer may move a booking at least this many hours before', 'move_times' => 'How many times a customer may move it', 'make_default' => 'Use this for services that have no policy of their own',
    'words_title' => 'Title shown to customers', 'words_text' => 'Words shown to customers',
];
