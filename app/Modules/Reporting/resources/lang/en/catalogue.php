<?php

declare(strict_types=1);

// The title and the explaining sentence of every report, and the words for columns that are not metrics (metric columns use reporting::metrics).
return [
    'sales_by_day' => [
        'title' => 'Sales by day',
        'description' => 'Every day of the period, from gross sales to net sales: orders, discounts, delivery, fees, tax, billed total, and (for finance) money received and given back.',
    ],
    'payment_methods' => [
        'title' => 'Payment methods',
        'description' => 'How customers pay: for each method, the orders, what was billed, its share of the billed total, and (for finance) the money received and given back.',
    ],
    'channels_languages' => [
        'title' => 'Channels and languages',
        'description' => 'Where orders were placed (website, app, staff) and in which language, with their sales and average order value.',
    ],
    'refunds_cancellations' => [
        'title' => 'Refunds and cancellations',
        'description' => 'Orders cancelled, and money given back, every day of the period.',
    ],
    'cod_aging' => [
        'title' => 'Cash on delivery: aging',
        'description' => 'Cash-on-delivery money that is stuck, as of now: cash not yet recorded, and cash recorded but not yet covered by a courier statement, by how old it is.',
    ],
    'low_stock' => [
        'title' => 'Low stock',
        'description' => 'Products with nothing left to sell, or at or below the level set as low, in each place, the least left first.',
    ],
    'stock_aging' => [
        'title' => 'Stock aging',
        'description' => 'The longest-sitting stock, in units: days since one last left in a parcel, or since the stock first arrived.',
    ],
    'common' => [
        'day' => 'Day',
        'method' => 'Payment method',
        'share' => 'Share of billed',
        'split' => 'Split by',
        'group' => 'Group',
        'kind' => 'Kind',
        'age_days' => 'Age (days)',
        'amount' => 'Amount',
        'product' => 'Product',
        'sku' => 'SKU',
        'location' => 'Place',
        'on_hand' => 'On hand',
        'reserved' => 'Reserved',
        'available' => 'Available',
        'low_level' => 'Low level',
        'units' => 'Units',
        'days' => 'Days',
    ],
];
