<?php

declare(strict_types=1);

return [
    'menu' => 'Settings', 'title' => 'Settings', 'saved' => 'Saved.',
    'blurbs' => [
        'branding' => 'Name, logo and colour.', 'business' => 'Legal name, address, BIN and TIN for receipts.', 'tax' => 'The tax switches.', 'locale' => 'Currency and languages (fixed).',
        'features' => 'Switches for features you can turn on and off.', 'maintenance' => 'Close the shop for a while.', 'audit' => 'Who changed what, and when.',
    ],
    'business' => [
        'title' => 'Business profile', 'intro' => 'Printed on receipts and packing slips. Only what you fill in is shown.',
        'legal_name' => 'Legal name', 'address' => 'Address', 'invoice_footer' => 'Footer text', 'phone' => 'Phone', 'email' => 'Email',
        'bin' => 'BIN', 'bin_help' => 'Business Identification Number, digits and hyphens. Leave blank if you are not registered.', 'tin' => 'TIN',
        'bin_label' => 'BIN', 'tin_label' => 'TIN',
        'shown_on' => 'These appear on the customer receipt and the packing slip. A receipt is not a tax invoice; the layout of a tax invoice needs your tax adviser.',
    ],
    'tax' => [
        'title' => 'Tax',
        'warning' => 'Do not turn tax on until your tax adviser has confirmed the rates and how prices should be shown. These switches change prices customers see and pay.',
        'rates_note' => 'Tax rates are not edited here.',
        'fields' => ['enabled' => 'Charge tax', 'prices_include_tax' => 'Prices already include tax', 'shipping_taxable' => 'Tax applies to delivery', 'cod_fee_taxable' => 'Tax applies to the cash-on-delivery fee'],
        'help' => [
            'enabled' => 'Off by default. While off, no tax is added anywhere.', 'prices_include_tax' => 'On: the price shown is the price paid, tax inside. Off: tax is added on top.',
            'shipping_taxable' => 'Whether the delivery charge is taxed.', 'cod_fee_taxable' => 'Whether the cash-on-delivery fee is taxed.',
        ],
    ],
    'locale' => [
        'title' => 'Currency and languages', 'intro' => 'These are fixed for now: reports and prices are in one currency.', 'currency' => 'Currency', 'default' => 'Default language', 'languages' => 'Languages offered',
    ],
    'features' => [
        'title' => 'Feature switches', 'intro' => 'A switch you set here wins; otherwise the server\'s setting applies. Nothing that moves real money is on this list.',
        'names' => ['cart_recovery' => 'Forgotten-cart reminders'], 'help' => ['cart_recovery' => 'Reminds customers who agreed to marketing messages about a cart they left.'],
        'on' => 'On', 'off' => 'Off', 'set_by_staff' => 'Set by staff', 'follows_default' => 'Follows the server\'s setting (:default)',
        'turn_on' => 'Turn on', 'turn_off' => 'Turn off', 'use_default' => 'Use the server\'s setting',
    ],
    'maintenance' => [
        'title' => 'Maintenance mode', 'intro' => 'Visitors see a "back soon" page. The admin area stays open, so you can always turn it off. Turning it on or off asks for your password.',
        'state' => 'The shop is', 'is_down' => 'closed for maintenance', 'is_up' => 'open', 'password' => 'Your password', 'password_help' => 'Type your own password to confirm.',
        'turn_on' => 'Close the shop', 'turn_off' => 'Open the shop', 'enabled' => 'The shop is closed for maintenance.', 'disabled' => 'The shop is open again.',
        'bypass' => 'Use this link to browse the shop while it is closed:', 'bypass_once' => 'It is shown only now. Keep it private.',
    ],
    'errors' => [
        'conflict' => 'Someone else saved these settings while you had the page open. Reload it to see their changes, then make yours again.',
        'invalid' => 'This value is not valid.', 'too_long' => 'This value is too long.', 'unknown' => 'That setting does not exist.', 'password' => 'The password is not right.',
        'already_down' => 'The shop is already closed.', 'not_down' => 'The shop is already open.',
    ],
];
