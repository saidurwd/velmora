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
    'best_sellers' => [
        'title' => 'Best sellers',
        'description' => 'The twenty products that sold the most units in the period (ties: the bigger sales first). Cancelled orders are left out.',
    ],
    'sales_by_product' => [
        'title' => 'Sales by product',
        'description' => 'Every product that sold in the period: units, returns, goods price, discounts and sales after discounts, biggest sales first. The variants of a product (sizes, colours) are added together.',
    ],
    'sales_by_category' => [
        'title' => 'Sales by category',
        'description' => 'Sales split by the primary category of each product, so every sale is counted once. A product moved to another category moves its past sales with it.',
    ],
    'sales_by_brand' => [
        'title' => 'Sales by brand',
        'description' => 'Sales split by the brand of each product. A product moved to another brand moves its past sales with it.',
    ],
    'tax_summary' => [
        'title' => 'Tax summary',
        'description' => 'The tax billed on orders placed in the period, by kind of charge and rate. It is what the orders say, not a tax return: cancelled orders are left out, refunds are not taken off, and there are no credit notes.',
    ],
    'delivery_performance' => [
        'title' => 'Delivery performance',
        'description' => 'How each courier did on the parcels handed to it in the period: delivered, failed, brought back and still on the way, with the average hours from handover to delivery. Parcels not yet handed over, and cancelled ones, are left out.',
        'columns' => [
            'courier' => 'Courier', 'parcels' => 'Parcels', 'delivered' => 'Delivered', 'failed' => 'Failed', 'returned' => 'Brought back', 'in_transit' => 'Still on the way',
            'delivery_rate' => 'Delivered share', 'return_rate' => 'Brought-back share', 'failed_attempts' => 'Failed attempts', 'avg_hours' => 'Average hours to deliver', 'cod_value' => 'Cash to collect',
        ],
    ],
    'returns_by_reason' => [
        'title' => 'Returns by reason',
        'description' => 'Return requests made in the period, by who started them (the customer, or the courier bringing a parcel back) and the reason chosen, most requests first. It does not show money: refunds are not linked to returns yet.',
        'columns' => [
            'kind' => 'Started by', 'reason' => 'Reason', 'requests' => 'Requests', 'units' => 'Units asked for', 'approved' => 'Approved', 'rejected' => 'Rejected', 'received' => 'Goods received',
            'share' => 'Share of requests',
        ],
    ],
    'coupon_performance' => [
        'title' => 'Coupon performance',
        'description' => 'What each coupon cost and what it was used on, for uses in the period, the dearest first. Uses on cancelled orders or in another currency are left out. An order that used two coupons counts in full under each, so do not add the goods price column up.',
        'columns' => [
            'coupon' => 'Coupon', 'uses' => 'Uses', 'discount' => 'Discount given', 'average_discount' => 'Average discount per use', 'gross_sales' => 'Goods price of those orders', 'discount_share' => 'Discount as share of goods price',
        ],
    ],
    'kind' => ['customer' => 'Customer', 'origin' => 'Courier brought it back'],
    'reason' => ['wrong_item' => 'Wrong item', 'damaged' => 'Damaged', 'not_as_described' => 'Not as described', 'changed_mind' => 'Changed their mind', 'other' => 'Other', 'undelivered' => 'Not delivered'],
    'common' => [
        'uncategorised' => 'Uncategorised',
        'no_brand' => 'No brand',
        'category' => 'Category',
        'brand' => 'Brand',
        'products' => 'Products',
        'variants' => 'Variants',
        'rank' => 'Rank',
        'charge' => 'Charge',
        'rate' => 'Rate',
        'prices' => 'Prices',
        'lines' => 'Lines',
        'taxable' => 'Taxable amount',
        'total' => 'Total',
        'tax_included' => 'Include tax',
        'tax_excluded' => 'Exclude tax',
        'charge_goods' => 'Goods',
        'charge_shipping' => 'Delivery',
        'charge_cod_fee' => 'Cash-on-delivery fee',
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
