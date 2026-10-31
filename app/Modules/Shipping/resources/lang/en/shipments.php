<?php

declare(strict_types=1);

return [
    'errors' => [
        'shipment_not_found' => 'That parcel was not found.',
        'order_not_found' => 'That order was not found.',
        'order_cancelled' => 'This order is cancelled.',
        'order_not_confirmed' => 'This order has not been confirmed yet.',
        'order_under_review' => 'This order is being checked by a person first.',
        'order_not_paid' => 'This order is not paid yet.',
        'no_lines' => 'Choose what goes in the parcel.',
        'quantity_invalid' => 'Enter a whole number of at least 1.',
        'line_not_in_order' => 'That item is not in this order.',
        'line_not_shippable' => 'Digital and service items are not posted.',
        'quantity_exceeds_remaining' => 'That is more than is left to send.',
        'courier_unavailable' => 'No courier is available. Choose another or use the manual courier.',
        'stock_not_held' => 'This order no longer holds that stock. Please check the stock first.',
        'courier_unreachable' => 'The courier could not be reached. The parcel is saved; try booking again later.',
        'cannot_book_now' => 'This parcel can no longer be booked.',
        'already_handed_over' => 'This parcel has already been handed over.',
        'shipment_cancelled' => 'This parcel is cancelled.',
        'booking_missing' => 'Book this parcel with the courier before handing it over.',
        'cannot_cancel_after_handover' => 'This parcel has left the shop and cannot be cancelled. Follow it to delivery or return.',
        'cannot_cancel_now' => 'This parcel cannot be cancelled right now.',
        'reason_required' => 'Please give a reason.',
    ],
];
