<?php

declare(strict_types=1);

return [
    'menu' => 'Orders', 'title' => 'Orders', 'order' => 'Order :number', 'back' => 'All orders', 'updated' => 'Order updated.', 'note_added' => 'Note added.',
    'search' => 'Order number, phone or name', 'filter' => 'Filter', 'all' => 'All', 'empty' => 'No orders match.', 'needs_review' => 'Needs review', 'only_review' => 'Only orders that need review',
    'cols' => ['number' => 'Order', 'placed' => 'Placed', 'customer' => 'Customer', 'total' => 'Total', 'status' => 'Status', 'payment' => 'Payment', 'fulfillment' => 'Delivery'],
    'axis' => ['status' => 'Order status', 'payment' => 'Payment', 'fulfillment' => 'Delivery'],
    'status' => ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
    'payment' => ['unpaid' => 'Unpaid', 'pending' => 'Payment pending', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'partially_refunded' => 'Partly refunded', 'refunded' => 'Refunded', 'failed' => 'Failed'],
    'fulfillment' => ['unfulfilled' => 'Not shipped', 'partially_fulfilled' => 'Partly shipped', 'fulfilled' => 'Shipped', 'partially_returned' => 'Partly returned', 'returned' => 'Returned'],
    'items' => 'Items', 'item' => ['product' => 'Product', 'sku' => 'SKU', 'qty' => 'Qty', 'unit' => 'Unit price', 'discount' => 'Discount', 'tax' => 'Tax', 'total' => 'Line total'],
    'totals' => ['subtotal' => 'Subtotal', 'discount' => 'Discounts', 'shipping' => 'Delivery', 'cod_fee' => 'Cash-on-delivery fee', 'tax_added' => 'Tax', 'tax_included' => 'Tax included in prices', 'grand' => 'Total'],
    'customer' => 'Customer', 'guest' => 'Guest checkout', 'phone_hidden' => 'Hidden: you need permission to see phone numbers', 'shipping_address' => 'Delivery address', 'billing_address' => 'Billing address',
    'payment_method' => 'Payment method', 'methods' => ['cod' => 'Cash on delivery'], 'channel' => 'Placed through', 'channels' => ['web' => 'Website', 'api' => 'App', 'admin' => 'Staff'],
    'history' => 'History', 'history_axis' => 'What changed', 'history_by' => 'By', 'history_reason' => 'Reason', 'system' => 'System', 'placed' => 'placed', 'staff' => 'Staff',
    'change' => 'Change state', 'move_to' => 'Move to', 'reason' => 'Reason', 'reason_hint' => 'Required when cancelling', 'apply' => 'Apply', 'no_moves' => 'No further changes are possible.', 'cancel' => 'Cancel order',
    'notes' => 'Notes', 'note_body' => 'Add a note', 'note_internal' => 'Internal (staff only)', 'note_customer' => 'Visible to the customer', 'note_add' => 'Add note', 'no_notes' => 'No notes yet.',
    'cancelled_because' => 'Cancelled: :reason',
    'review_title' => 'Needs review', 'review_help' => 'This order was placed but held for a person to look at before it can be confirmed.', 'review_flags' => 'Why', 'review_reason' => 'What you checked (required)', 'review_clear' => 'Mark as reviewed',
    'review_cleared' => 'Marked as reviewed. You can confirm the order now.', 'review_cleared_note' => 'Review cleared: :reason', 'block_title' => 'Stop this customer ordering again', 'block_phone' => 'Block this phone number', 'block_address' => 'Block this address',
    'block_reason' => 'Reason (optional)', 'block_apply' => 'Block', 'blocked' => 'Blocked. They will not be able to place new orders.', 'block_nothing' => 'Choose what to block.', 'flags_none' => 'No warning flags.',
    'flag' => ['velocity_phone' => 'Many orders from one phone in a day (:orders_24h so far; limit :limit)', 'address_many_phones' => 'One address used by many phones (:phones_30d in 30 days; limit :limit)'],
    'errors' => [
        'illegal_transition' => 'That change is not allowed from “:from” to “:to”.', 'already_in_state' => 'The order is already in that state.', 'cancel_after_shipping' => 'An order that has been shipped cannot be cancelled. Record a return instead.',
        'cancel_needs_refund' => 'Money has been taken for this order. Refund it first, then cancel.', 'reason_required' => 'Please give a reason for cancelling.',
        'complete_needs_delivery_and_payment' => 'An order can be completed only when it is delivered and paid.', 'order_cancelled' => 'This order is cancelled.', 'confirm_before_shipping' => 'Confirm the order before shipping it.',
        'review_pending' => 'This order is flagged for review. Look at it and mark it as reviewed before confirming.', 'not_under_review' => 'This order is not waiting for review.',
        'changed_meanwhile' => 'The order was changed by someone else. Reload and try again.', 'breakdown_mismatch' => 'The order totals do not add up.', 'lines_mismatch' => 'The order lines do not match the priced lines.', 'address_required' => 'A delivery address is required.',
    ],
];
