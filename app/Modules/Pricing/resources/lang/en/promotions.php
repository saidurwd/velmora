<?php

declare(strict_types=1);

return [
    'menu_coupons' => 'Coupons', 'menu_promotions' => 'Automatic offers', 'saved' => 'Saved.', 'back' => 'Back', 'save' => 'Save', 'edit' => 'Edit', 'view' => 'View settings', 'filter' => 'Search', 'active' => 'Active (switch off to stop it working)',
    'starts' => 'Starts (optional)', 'ends' => 'Ends (optional)', 'ends_help' => 'The offer stops at this exact time; it does not work during or after it.', 'dhaka_time' => 'Times are in Bangladesh time (Dhaka).',
    'coupons' => [
        'title' => 'Coupons', 'add' => 'Add a coupon', 'search' => 'Search by code', 'empty' => 'No coupons yet.', 'new_title' => 'New coupon', 'edit_title' => 'Edit coupon :code',
        'code_help' => 'Letters, numbers, dashes. Customers type it in the cart; capitals and small letters both work. It cannot be changed later.', 'code_fixed' => 'cannot be changed',
        'description' => 'Note for staff', 'description_help' => 'Only staff see this.',
        'cols' => ['code' => 'Code', 'discount' => 'Discount', 'used' => 'Used', 'given' => 'Given away', 'status' => 'Status'],
        'states' => ['live' => 'Working', 'off' => 'Switched off', 'scheduled' => 'Not started', 'ended' => 'Ended', 'used_up' => 'Used up'],
        'discount' => 'Discount', 'type' => 'Kind of coupon', 'types' => ['percent' => 'A percentage off', 'fixed' => 'A fixed amount off', 'free_shipping' => 'Free delivery'],
        'value' => 'Percentage (like 10 or 7.5) or amount in taka', 'value_help' => 'For a percentage coupon type the percent; for a fixed amount type the taka.',
        'max_discount' => 'Most it can take off (৳)', 'max_discount_help' => 'Optional cap for a percentage coupon.', 'min_spend' => 'Minimum spend (৳)', 'min_spend_help' => 'Counted on the items the coupon applies to. Leave empty for none.',
        'applies_to' => 'What it applies to', 'scope' => 'Applies to', 'scopes' => ['all' => 'Everything in the cart', 'products' => 'Only chosen products', 'categories' => 'Only chosen categories'],
        'skus' => 'Product SKUs', 'skus_help' => 'One SKU per line or separated by commas. The coupon applies to those products and all their variants.', 'categories' => 'Categories', 'categories_help' => 'Products in these categories and their sub-categories.',
        'when' => 'When and how often', 'usage_limit' => 'Total uses allowed', 'usage_limit_help' => 'Leave empty for no limit. It cannot be set below the number already used.',
        'per_customer' => 'Uses per customer', 'per_customer_help' => 'Leave empty for no limit. A customer is an account or, for guests, a phone number.',
    ],
    'usage' => [
        'title' => 'Coupon :code', 'uses' => 'Times used', 'given' => 'Total discount given', 'people' => 'Different customers', 'orders' => 'Orders that used it', 'none' => 'Not used yet.', 'account' => 'Signed-in customer', 'guest' => 'Guest',
        'cols' => ['when' => 'When', 'order' => 'Order', 'amount' => 'Discount', 'who' => 'Customer'],
    ],
    'promotions' => [
        'title' => 'Automatic offers', 'add' => 'Add an offer', 'help' => 'These apply to a cart by themselves, with no code, when the cart qualifies. If several qualify, the best one for the customer is used.', 'empty' => 'No automatic offers yet.',
        'new_title' => 'New automatic offer', 'edit_title' => 'Edit automatic offer', 'label' => 'Text shown in the cart', 'label_help' => 'For example “10% off orders over ৳2,000”.', 'min_spend' => 'Cart must be at least (৳)', 'min_spend_help' => 'The value of all items in the cart. Leave empty for none.',
        'cols' => ['label' => 'Offer', 'discount' => 'Discount', 'min_spend' => 'Cart at least', 'status' => 'Status'],
    ],
    'errors' => [
        'coupon_code_invalid' => 'The code must be 3 to 64 letters, numbers, dashes or underscores.', 'coupon_code_taken' => 'There is already a coupon with this code.', 'coupon_percent_range' => 'A percentage must be between 0.01 and 100.',
        'coupon_amount_required' => 'Enter the amount in taka.', 'coupon_scope_empty' => 'Choose at least one product or category, or apply the coupon to everything.', 'window_invalid' => 'The end must be after the start.',
        'coupon_numbers_invalid' => 'One of the numbers is not valid. Limits must be at least 1.', 'coupon_limit_below_used' => 'This coupon has already been used :used times, so the limit cannot be lower than that.',
        'money_invalid' => 'Enter an amount in taka, like 500 or 500.50.', 'percent_invalid' => 'Enter a percentage like 10 or 7.5 (between 0.01 and 100).', 'sku_unknown' => 'We could not find these SKUs: :skus.', 'category_unknown' => 'One of the categories is not valid.',
    ],
];
