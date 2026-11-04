<?php

declare(strict_types=1);

return [
    'title' => 'My bookings', 'join' => 'Join online', 'empty' => 'You have no bookings yet. When you book a service, it appears here.', 'upcoming' => 'Coming up', 'past' => 'Earlier', 'order' => 'Order', 'with' => 'With', 'open' => 'Open', 'back' => 'Back to my bookings',
    'status' => ['held' => 'Waiting for payment', 'confirmed' => 'Confirmed', 'in_progress' => 'Under way', 'completed' => 'Done', 'cancelled' => 'Cancelled', 'no_show' => 'Missed'],
    'cancel_title' => 'Cancel this booking', 'cancel_free' => 'You can cancel for free. :amount is due back to you.',
    'cancel_fee' => 'Cancelling now keeps :percent% of the price (:fee). :amount is due back to you.', 'cancel_button' => 'Cancel booking', 'reason' => 'Reason (optional)',
    'refund_note' => 'Our team approves the refund and sends the money back to how you paid.',
    'move_title' => 'Move this booking', 'move_help' => 'You can move it :left more time(s), at least :hours hours before it starts. Pick a day, then a new time.', 'move_closed' => 'This booking can no longer be moved (it must be at least :hours hours before it starts, and within the number of moves allowed).',
    'day' => 'Day', 'show_times' => 'Show times', 'no_times' => 'There are no free times on this day. Try another day.',
    'done_title' => 'Was the service delivered?', 'done_button' => 'Mark as done',
    'ended' => ['cancelled' => 'This booking was cancelled', 'no_show' => 'This booking was missed', 'fee' => 'Kept: :fee', 'refund' => 'Due back to you: :amount'],
    'cancelled' => 'Your booking was cancelled.', 'moved' => 'Your booking was moved.', 'completed' => 'Thank you. The booking is marked as done.',
    'guest_intro' => 'To see the bookings of order :number, we will send a code to the phone ending in :end.', 'send_code' => 'Send me a code', 'code_label' => 'Code', 'open_bookings' => 'Open my bookings',
    'code' => ['sent' => 'We sent you a code.', 'later' => 'Please wait a little before asking for another code.', 'invalid' => 'That code did not work.'],
];
