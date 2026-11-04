<?php

declare(strict_types=1);

return [
    'menu' => ['bookings' => 'Bookings', 'calendar' => 'Booking calendar'], 'title' => 'Bookings', 'any' => 'Any', 'from' => 'From', 'to' => 'To', 'sku' => 'Service code', 'filter' => 'Filter', 'none' => 'No bookings match.',
    'col' => ['status' => 'Status', 'resource' => 'Person or room', 'order' => 'Order', 'when' => 'When', 'service' => 'Service', 'phone' => 'Phone', 'paid' => 'Paid'],
    'view' => 'View', 'day' => 'Day', 'week' => 'Week', 'date' => 'Date', 'show' => 'Show', 'navigate' => 'Move through the calendar', 'previous' => 'Earlier', 'next' => 'Later',
    'capped' => 'Too many bookings to show them all. Narrow it down to one person or room.', 'no_resources' => 'There are no people or rooms yet.', 'zone_note' => 'Times are in Dhaka time (:zone).',
    'back' => 'Back to bookings', 'moved' => 'Times moved', 'completed' => 'Completed', 'by' => ['customer' => 'the customer', 'staff' => 'staff', 'system' => 'the system'],
    'terms' => 'Terms this booking was confirmed under', 'terms_window' => 'Cancelling at least :hours hours before: :percent% of the price is kept.', 'terms_late' => 'Cancelling closer than that: :percent% is kept.',
    'terms_no_show' => 'Not turning up: :percent% is kept.', 'terms_move' => 'The customer may move it up to :max times, at least :hours hours before it starts.',
    'ended' => ['cancelled' => 'Cancelled', 'no_show' => 'Missed', 'line' => 'By :by. Paid :paid, kept :fee (:percent%).', 'refund_due' => 'Refund due: :amount', 'approve_in_payments' => 'Nothing is refunded automatically. Approve the refund from the order.', 'open_order' => 'Open the order'],
    'waived' => 'fee waived', 'reason' => 'Reason', 'act' => ['complete' => 'Mark as done', 'no_show' => 'Record a no-show', 'cancel' => 'Cancel booking', 'move' => 'Move booking', 'reassign' => 'Give to someone else'],
    'preview' => 'If you do this now: :fee is kept (:percent%) and :amount is due back to the customer.', 'waive' => 'Waive the fee', 'move_help' => 'Staff can move it any time. Choose a day and a free time on the same person or room.',
    'new_time' => 'New time', 'reassign_none' => 'No other person or room is free at this time.', 'history' => 'History', 'messages' => 'Messages sent',
    'done' => ['cancelled' => 'The booking was cancelled.', 'no_show' => 'The no-show was recorded.', 'completed' => 'The booking was marked as done.', 'moved' => 'The booking was moved.', 'reassigned' => 'The booking was given to someone else.'],
];
