<?php

declare(strict_types=1);

return [
    'back' => 'Back', 'print' => 'Print',
    'receipt' => [
        'title' => 'Order receipt', 'number' => 'Order', 'cancelled' => 'Cancelled', 'parties' => 'Customer and delivery', 'customer' => 'Customer', 'deliver_to' => 'Deliver to', 'items' => 'Items ordered',
        'item' => 'Item', 'qty' => 'Qty', 'unit' => 'Price each', 'amount' => 'Amount', 'line_discount' => 'Discount', 'payment' => 'Payment', 'staff_link' => 'Print order receipt',
        'payment_status' => ['unpaid' => 'not paid yet', 'pending' => 'waiting for payment', 'partially_paid' => 'partly paid', 'paid' => 'paid', 'partially_refunded' => 'partly refunded', 'refunded' => 'refunded', 'failed' => 'payment failed'],
        'not_tax_invoice' => 'This is a receipt for your order. It is not a VAT invoice.',
        'not_reconciled' => 'The figures on this order do not add up. Do not give this receipt to the customer; ask a developer to check the order.',
    ],
];
