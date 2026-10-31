<?php

declare(strict_types=1);

return [
    'errors' => [
        'initiation_failed' => 'We could not reach the payment service just now. Nothing was charged. Please try again in a moment.', 'method_unavailable' => 'This way of paying is not available right now.',
        'payments_frozen' => 'Online payments are paused for a short while. Nothing was charged. Please try again later, or choose cash on delivery if it is offered.', 'amount_out_of_range' => 'This amount cannot be paid online.',
        'already_paid' => 'This order is already paid.', 'under_review' => 'Your payment is being checked. We will contact you shortly.', 'payment_expired' => 'The time to pay for this order has run out.',
        'too_many_attempts' => 'Too many payment attempts. Please contact us.', 'currency_unsupported' => 'This currency cannot be paid online.', 'unknown_provider' => 'This payment service is not available.',
        'provider_unavailable' => 'The payment service did not answer.', 'provider_rejected' => 'The payment service could not start this payment.', 'provider_bad_response' => 'The payment service sent an answer we could not use.',
        'provider_auth_failed' => 'The shop\'s payment account was not accepted by the payment service.', 'provider_inactive' => 'The shop\'s payment account is switched off at the payment service.', 'provider_invalid_request' => 'The payment service refused the request.',
        'provider_not_configured' => 'Online payment is not set up yet.', 'reference_too_long' => 'The payment reference is too long.', 'callback_url_too_long' => 'A return address is too long for the payment service.', 'provider_amount_invalid' => 'The payment service sent an amount we could not read.', 'callback_unreadable' => 'The notification could not be read.', 'refund_not_supported' => 'This payment service cannot refund automatically.',
    ],
];
