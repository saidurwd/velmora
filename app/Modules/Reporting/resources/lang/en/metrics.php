<?php

declare(strict_types=1);

// One entry per metric: the name staff see and the sentence that says exactly how it is worked out. The formula itself is in Metrics/.
return [
    'orders' => [
        'label' => 'Orders',
        'definition' => 'The number of orders placed in the period, not counting cancelled ones.',
    ],
    'gross_sales' => [
        'label' => 'Gross sales',
        'definition' => 'The price of the goods ordered before any discount: the order subtotals added up. Cancelled orders are not counted. When prices include tax, the tax is inside this figure.',
    ],
    'discounts' => [
        'label' => 'Discounts',
        'definition' => 'Every discount given on those orders: discounts on single items and discounts on the whole order (coupons and offers).',
    ],
    'delivery_charges' => [
        'label' => 'Delivery charges',
        'definition' => 'What customers were charged for delivery on those orders.',
    ],
    'cod_fees' => [
        'label' => 'Cash-on-delivery fees',
        'definition' => 'What customers were charged for paying in cash at the door.',
    ],
    'tax' => [
        'label' => 'Tax',
        'definition' => 'The tax on those orders, as billed. Zero while the shop does not charge tax.',
    ],
    'billed_total' => [
        'label' => 'Billed total',
        'definition' => 'What the customers were billed: gross sales minus discounts, plus delivery charges and cash-on-delivery fees, plus tax when prices do not already include it.',
    ],
    'average_order_value' => [
        'label' => 'Average order value',
        'definition' => 'The billed total divided by the number of orders, rounded to the nearest paisa.',
    ],
    'cancelled_orders' => [
        'label' => 'Cancelled orders',
        'definition' => 'The number of orders placed in the period that were cancelled afterwards. They are left out of every other figure.',
    ],
    'cancelled_value' => [
        'label' => 'Value of cancelled orders',
        'definition' => 'What the cancelled orders would have been billed.',
    ],
    'collected' => [
        'label' => 'Money received',
        'definition' => 'Payments received in the period, by the day they were paid. Cash on delivery counts on the day the cash was collected at the door, not the day it reached the shop.',
    ],
    'refunded' => [
        'label' => 'Money given back',
        'definition' => 'Refunds that were completed in the period, by the day they completed, including refunds of cancelled orders.',
    ],
    'net_sales' => [
        'label' => 'Net sales',
        'definition' => 'Gross sales minus discounts minus refunds of orders that were not cancelled, by the day of the refund. Refunds of cancelled orders are left out because those orders were never counted in sales. Delivery charges and tax are not taken out separately.',
    ],
];
