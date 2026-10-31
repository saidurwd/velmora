<?php

declare(strict_types=1);

return [
    'errors' => [
        'initiation_failed' => 'We could not reach the payment service just now. Nothing was charged. Please try again in a moment.', 'method_unavailable' => 'This way of paying is not available right now.',
        'payments_frozen' => 'Online payments are paused for a short while. Nothing was charged. Please try again later, or choose cash on delivery if it is offered.', 'amount_out_of_range' => 'This amount cannot be paid online.',
        'already_paid' => 'This order is already paid.', 'under_review' => 'Your payment is being checked. We will contact you shortly.', 'payment_expired' => 'The time to pay for this order has run out.',
        'too_many_attempts' => 'Too many payment attempts. Please contact us.', 'currency_unsupported' => 'This currency cannot be paid online.', 'unknown_provider' => 'This payment service is not available.',
    ],
];
