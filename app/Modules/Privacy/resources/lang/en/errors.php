<?php

declare(strict_types=1);

return [
    'not_a_customer' => 'This is only for customer accounts.',
    'already_open' => 'You already have a request of this kind in progress.',
    'too_soon' => 'You asked for a copy a short while ago. Please try again tomorrow.',
    'expired' => 'This copy is no longer available. You can ask for a new one.',
    'not_found' => 'We could not find that.',
    'not_waiting' => 'That request has already been dealt with.',
    'blocked' => 'This customer cannot be erased yet: something is still open (see below). Try again when it is finished.',
    'reason_required' => 'Please give a reason. The customer is told.',
    'blockers' => [
        'orders' => ['open' => 'An order is still being handled.'],
        'bookings' => ['upcoming' => 'A booking is still to come.'],
        'identity' => ['staff_account' => 'This is a staff account, not a customer.'],
    ],
];
