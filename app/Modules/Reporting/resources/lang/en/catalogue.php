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
    'orders_file' => [
        'title' => 'Orders file',
        'description' => 'One row per order placed in the period, for the orders export. Names and phone numbers are masked unless you may see personal data.',
        'columns' => ['number' => 'Order', 'placed' => 'Placed (UTC)', 'status' => 'Status', 'payment_status' => 'Payment', 'fulfillment' => 'Delivery', 'channel' => 'Channel', 'payment_method' => 'Payment method', 'language' => 'Language', 'items' => 'Items', 'currency' => 'Currency', 'total' => 'Total', 'name' => 'Name', 'phone' => 'Phone'],
    ],
    'customers_file' => [
        'title' => 'Customers file',
        'description' => 'One row per customer who ordered in the period, for the customers export. Names and phone numbers are masked unless you may see personal data.',
        'columns' => ['name' => 'Name', 'phone' => 'Phone', 'orders' => 'Orders', 'first_order' => 'First order', 'last_order' => 'Last order', 'total' => 'Spent (not cancelled, BDT)'],
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
    'refund_cancel_reasons' => [
        'title' => 'Reasons for refunds and cancellations',
        'description' => 'Why orders were cancelled (by the day they were cancelled) and why money was given back (by the day the refund went through), by reason chosen, most first in each part. Cancellations and refunds made before reasons were recorded are shown together as “Not recorded”. Other currencies are left out.',
        'columns' => ['type' => 'Type', 'reason' => 'Reason', 'count' => 'Count', 'value' => 'Value', 'share' => 'Share'],
    ],
    'reason_types' => ['cancellation' => 'Cancelled orders', 'refund' => 'Refunds', 'not_recorded' => 'Not recorded'],
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
    'customer_cohorts' => [
        'title' => 'Customer cohorts',
        'description' => 'Signed-in customers grouped by the month of their first order, and the share of each group that ordered again 1 to 6 months later. Guests are not included (there is no safe way to know two guest orders are one person), and cancelled orders do not count. Worked out again every night, so today’s orders show tomorrow. A month that has not started yet is left blank.',
        'columns' => [
            'cohort' => 'First order month', 'customers' => 'Customers', 'month_1' => 'Ordered again after 1 month', 'month_2' => 'After 2 months', 'month_3' => 'After 3 months', 'month_4' => 'After 4 months',
            'month_5' => 'After 5 months', 'month_6' => 'After 6 months', 'orders' => 'Orders so far', 'sales' => 'Sales after discounts so far',
        ],
    ],
    'booking_utilisation' => [
        'title' => 'Booking utilisation and no-shows',
        'description' => 'Each service resource over the period: bookings by what became of them, how much of its working time was booked, and the no-show rate (no-shows out of the bookings whose time came and were not cancelled). A booking belongs to the day its service starts. No-shows and cancelled bookings add no booked time, and bookings still held (not yet paid for) are not counted. Working time follows the weekly timetable, day exceptions and shop holidays.',
        'columns' => [
            'resource' => 'Resource', 'bookings' => 'Bookings', 'completed' => 'Completed', 'no_shows' => 'No-shows', 'cancelled' => 'Cancelled', 'no_show_rate' => 'No-show rate',
            'booked_minutes' => 'Minutes booked', 'open_minutes' => 'Minutes open', 'utilisation' => 'Share of open time booked',
        ],
    ],
    'search_gaps' => [
        'title' => 'Searches with no results',
        'description' => 'What customers searched for and did not find, most searched first, so you can add the product or a synonym. Only words searched at least twice are listed. Words that look like personal data (an email address, or seven or more digits) are added together in one hidden row, and everything searched once is one row at the end.',
        'hidden' => '(hidden: looks like personal data)',
        'other' => '(searched once: not listed)',
        'columns' => ['query' => 'Searched for', 'language' => 'Language', 'times' => 'Times', 'last_searched' => 'Last searched'],
    ],
    'daily_close' => [
        'title' => 'Daily close sheet',
        'description' => 'One page to close a day (or a period): what was sold and taken off, what was cancelled, the money received by payment method (cash on delivery is its own method, so the cash recorded is its row), the money given back, and the net. It uses the same figures as the sales summary, so the two always agree.',
        'columns' => ['section' => 'Part', 'line' => 'Line', 'count' => 'Count', 'amount' => 'Amount'],
        'sections' => ['sales' => 'Sales', 'money' => 'Money received', 'result' => 'Result'],
        'lines' => [
            'orders' => 'Orders (amount: billed total)', 'gross_sales' => 'Gross sales', 'discounts' => 'Discounts', 'delivery_charges' => 'Delivery charges', 'cod_fees' => 'Cash-on-delivery fees', 'tax' => 'Tax billed',
            'cancelled' => 'Cancelled orders', 'collected' => 'Total received', 'refunded' => 'Money given back', 'net_sales' => 'Net sales',
        ],
    ],
    'language' => ['en' => 'English', 'bn' => 'Bangla'],
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
