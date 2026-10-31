<?php

declare(strict_types=1);

return [
    'sms_placed' => ':shop: Order :number received. Total :total.:cod We will confirm it soon.', 'sms_cod' => ' Pay cash on delivery.',
    'email_subject' => 'Your order :number', 'email_heading' => 'Thank you! Order :number', 'email_intro' => 'Hello :name, we have received your order. Here is what you ordered:',
    'email_link' => 'View your order', 'email_footer' => 'You are receiving this because an order was placed with this email address.',
    'sms_cancelled_stale' => ':shop: Order :number was cancelled because we could not confirm it in time. You are welcome to order again.', 'payment_needs_review_note' => 'Payment needs review: :reason',
    'payment_risk_note' => 'The payment gateway rates this payment as high risk. Please check the customer before shipping.',
    'refund_note' => 'Refund completed: :amount (:kind).', 'sms_refunded' => ':shop: :amount has been refunded for order :number. It can take a few days to show in your account.',
    'sms_shipped' => ':shop: your order :number is on its way.',
    'sms_delivered' => ':shop: your order :number was delivered. Thank you for shopping with us!',
    'sms_delivery_failed' => ':shop: we could not deliver your order :number. We will try again; please keep your phone on.',
    'sms_delivery_failed_final' => ':shop: we could not deliver your order :number after several tries. Please call us.',
    'note_delivered' => 'All parcels of this order were delivered.',
    'note_delivery_failed' => 'A delivery attempt failed (attempt :attempts).',
    'note_returned' => 'A parcel is coming back to the shop.',
];
