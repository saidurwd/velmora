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
        'refund_not_found' => 'That refund or payment was not found.', 'refund_reason_required' => 'Please give a reason.', 'refund_amount_invalid' => 'Enter an amount to refund.', 'refund_nothing_captured' => 'No money has been received for this payment, so there is nothing to refund.',
        'refund_exceeds_captured' => 'That is more than can still be refunded.', 'refund_transaction_invalid' => 'That payment attempt cannot be refunded.', 'refund_not_pending' => 'This refund is no longer waiting for a decision.', 'refund_needs_second_person' => 'A refund this large must be approved by someone other than the person who asked for it.',
        'refund_not_approved' => 'This refund has not been approved.', 'refund_already_processing' => 'This refund is already being sent.', 'refund_wrong_method' => 'This refund is paid the other way (by hand, or through the gateway).', 'refund_proof_required' => 'Enter the reference or slip number that proves the money was paid back.',
        'cod_order_not_found' => 'That order was not found.',
        'cod_not_cod_order' => 'This order is not cash on delivery.',
        'cod_order_cancelled' => 'This order was cancelled, so no cash can be recorded for it.',
        'cod_already_collected' => 'The cash for this order is already recorded (or the order is already paid).',
        'cod_date_invalid' => 'That date is not possible: it is in the future or before the order was placed.',
        'cod_statement_invalid' => 'Enter the courier and the statement number.',
        'cod_amount_invalid' => 'Enter a valid amount.',
        'cod_items_required' => 'List at least one parcel from the statement.',
        'cod_items_duplicated' => 'A parcel is listed twice.',
        'cod_collection_not_found' => 'One of the listed parcels has no recorded cash collection.',
        'cod_currency_mixed' => 'The listed parcels are in different currencies.',
        'cod_already_remitted' => 'One of the listed parcels is already on another courier statement.',
        'cod_statement_duplicate' => 'This statement was already entered for this courier.',
    ],
];
