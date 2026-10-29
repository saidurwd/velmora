<?php

declare(strict_types=1);

return [
    'menu' => ['zones' => 'Delivery zones', 'methods' => 'Delivery methods'], 'saved' => 'Saved.', 'add' => 'Add', 'edit' => 'Edit', 'save' => 'Save', 'back' => 'Back', 'yes' => 'Yes', 'no' => 'No', 'active' => 'Active', 'inactive' => 'Off',
    'zones' => [
        'title' => 'Delivery zones', 'add' => 'Add a zone', 'edit_title' => 'Edit zone', 'new_title' => 'New delivery zone', 'name' => 'Zone name', 'help' => 'A zone is a group of districts with the same delivery prices. Every district can be in only one zone.',
        'cols' => ['name' => 'Zone', 'districts' => 'Districts', 'cod' => 'Cash on delivery', 'status' => 'Status'], 'fallback_badge' => 'Everywhere else',
        'no_fallback' => 'There is no active “everywhere else” zone. Customers in districts that no zone covers cannot be offered delivery. Mark one zone as “everywhere else”.',
        'fallback' => 'This is the “everywhere else” zone', 'fallback_help' => 'Used for every district that no other zone covers. Only one zone can be it; choosing it here moves the setting from the other zone.',
        'cod' => 'Cash on delivery is offered in this zone', 'sort' => 'Order in lists', 'districts' => 'Districts in this zone', 'districts_help' => 'Ticking a district that belongs to another zone moves it here.', 'in_zone' => '(now in :zone)',
        'rates' => 'Delivery prices', 'rates_help' => 'For each method, the price for a parcel up to a weight. Leave the weight empty for “any heavier parcel”. Leave a method with no rows to not offer it in this zone.',
        'weight' => 'Up to (kg)', 'price' => 'Price (৳)', 'row' => 'Row :n', 'no_methods' => 'Add a delivery method first.',
    ],
    'methods' => [
        'title' => 'Delivery methods', 'add' => 'Add a method', 'edit_title' => 'Edit method', 'new_title' => 'New delivery method', 'code' => 'Code', 'code_help' => 'Short and fixed, like standard or express. It cannot be changed later.',
        'name' => 'Name shown to customers', 'estimate' => 'Delivery time (optional)', 'estimate_help' => 'Shown to the customer, for example “Dhaka 1–2 working days”.',
        'cols' => ['name' => 'Method', 'code' => 'Code', 'cod' => 'Cash on delivery', 'cod_fee' => 'Cash fee', 'free_over' => 'Free over', 'status' => 'Status'],
        'cod' => 'Cash on delivery is possible with this method', 'cod_fee' => 'Extra fee for cash on delivery (৳)', 'cod_fee_help' => 'Added to the order when the customer pays cash. Leave empty or 0 for no fee.',
        'free_over' => 'Free delivery when the order is at least (৳)', 'free_over_help' => 'Measured after discounts. Leave empty for never free.', 'sort' => 'Order in lists', 'none' => '—',
    ],
    'errors' => [
        'fallback_must_be_active' => 'The “everywhere else” zone must be active.', 'rate_two_open_bands' => 'Only one row per method can have an empty weight (“any heavier parcel”).',
        'rate_duplicate_weight' => 'Two rows for the same method have the same weight. Each weight can appear once.', 'rate_invalid' => 'A delivery price or weight is not valid. Use numbers like 60 or 0.5.',
        'district_unknown' => 'One of the districts is not valid.', 'money_invalid' => 'Enter an amount in taka, like 60 or 60.50.',
    ],
];
