<?php

declare(strict_types=1);

return [
    'menu_settings' => 'Cash-order rules', 'menu_blocklist' => 'Block list', 'save' => 'Save', 'saved' => 'Saved. The new limits apply to the next order.', 'view_only' => 'You can look at these limits but not change them.',
    'settings_title' => 'Cash-on-delivery rules', 'settings_help' => 'These limits protect the shop from fake and abusive cash-on-delivery orders. Orders that break a “refuse” rule cannot be placed; orders that break a “review” rule are placed but held until a person looks at them.',
    'enabled' => 'Use these rules', 'enabled_help' => 'Switching this off turns off every rule below. The block list always applies.',
    'max_open' => 'Open cash orders allowed per phone number', 'max_open_help' => 'Refuses a new cash order when the phone already has this many that are not yet delivered or cancelled. 0 means no limit.',
    'high_value' => 'Cash orders above this amount need an SMS code (৳)', 'high_value_help' => 'The customer must enter a code we text to their phone. 0 turns this off.',
    'velocity' => 'Orders from one phone in a day before review', 'velocity_help' => 'When a phone has already placed this many orders in the last 24 hours, the next one is held for review. 0 means never.',
    'address_phones' => 'Different phones using one address before review', 'address_phones_help' => 'When this many different phone numbers have used the same address in 30 days, the order is held for review. 0 means never.',
    'blocklist_title' => 'Block list', 'blocklist_help' => 'Phone numbers and addresses that may not place orders. Only a scrambled form is kept, so the list shows a hidden number or just the area. Addresses are added from an order page.',
    'phone' => 'Phone number to block', 'reason' => 'Reason (optional)', 'add_phone' => 'Block this number', 'added' => 'Blocked.', 'updated' => 'Updated.', 'empty' => 'Nothing is blocked.',
    'cols' => ['kind' => 'Kind', 'what' => 'Which', 'reason' => 'Reason', 'added' => 'Added', 'status' => 'Status', 'action' => 'Action'], 'kinds' => ['phone' => 'Phone', 'address' => 'Address'],
    'states' => ['blocked' => 'Blocked', 'off' => 'Not blocked', 'expired' => 'Expired'], 'unblock' => 'Stop blocking', 'block_again' => 'Block again',
    'errors' => ['money_invalid' => 'Enter an amount in taka, like 10000, or 0 to turn it off.', 'setting_out_of_range' => 'A number is outside what is allowed (:min to :max).', 'phone_invalid' => 'Enter a valid Bangladesh mobile number, like 01712 345678.', 'blocklist_entry_missing' => 'That entry does not exist.'],
];
