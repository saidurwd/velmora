<?php

declare(strict_types=1);

return [
    'menu' => ['prices' => 'Prices', 'bulk' => 'Bulk price change'],
    'title' => 'Prices', 'search' => 'SKU or product name', 'find' => 'Find', 'sku' => 'SKU', 'product' => 'Product', 'price' => 'Price',
    'no_price' => 'No price', 'none_found' => 'No matching variants.', 'saved' => 'Saved.',
    'regular' => 'Regular price', 'amount' => 'Price (৳)', 'amount_help' => 'In taka, e.g. 1250 or 1,250.50.', 'compare_at' => 'Compare-at ("was") price (৳)', 'compare_at_help' => 'Optional. Must be higher than the price.', 'save' => 'Save price',
    'sales' => 'Sales', 'no_sales' => 'No upcoming sales.', 'cancel_sale' => 'Cancel sale', 'schedule_sale' => 'Schedule a sale', 'sale_amount' => 'Sale price (৳)',
    'starts_at' => 'Starts', 'ends_at' => 'Ends', 'dhaka_time' => 'Dhaka time.', 'ends_help' => 'Dhaka time. The sale stops at this moment.',
    'bulk' => [
        'title' => 'Bulk price change', 'skus' => 'SKUs (one per line)', 'percent' => 'Change (%)', 'percent_help' => 'Use a minus sign to cut, e.g. -10 or 7.5.', 'percent_invalid' => 'Enter a percentage between -500 and 500.',
        'round' => 'Round to', 'round_none' => 'No rounding', 'round_1' => 'Nearest ৳1', 'round_5' => 'Nearest ৳5', 'preview' => 'Preview', 'no_match' => 'None of those SKUs exist.', 'nothing' => 'Nothing would change.',
        'old' => 'Now', 'new' => 'After', 'warning' => 'Note', 'apply' => 'Apply to :count prices', 'applied' => ':count prices updated.',
        'warnings' => ['unchanged' => 'Unchanged', 'at_or_above_the_compare_at_price' => 'At or above the compare-at price', 'free' => 'Free'],
    ],
];
