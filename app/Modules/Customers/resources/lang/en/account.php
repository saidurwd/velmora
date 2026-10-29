<?php

declare(strict_types=1);

return [
    'title' => 'My account',
    'welcome' => 'Welcome, :name.',
    'addresses' => [
        'title' => 'My addresses', 'add' => 'Add an address', 'add_title' => 'Add an address', 'edit_title' => 'Edit address', 'edit' => 'Edit', 'delete' => 'Delete', 'save' => 'Save address',
        'saved' => 'Your address was saved.', 'deleted' => 'The address was removed.', 'confirm_delete' => 'Remove this address?', 'default' => 'Default', 'make_default' => 'Use as my default address',
        'empty_title' => 'No saved addresses yet', 'empty_text' => 'Add one now and checkout will be faster next time.',
        'label' => 'Name for this address (optional)', 'label_help' => 'For example Home or Office.', 'recipient' => 'Recipient name', 'phone' => 'Mobile number', 'phone_help' => 'The courier will call this number. Example: 01712345678.',
        'division' => 'Division', 'district' => 'District', 'upazila' => 'Upazila / Thana', 'upazila_help' => 'Pick your upazila or thana. For Dhaka city, pick the thana and write your area below.', 'choose' => 'Choose…',
        'area' => 'Area / locality (optional)', 'area_help' => 'For example Mirpur 10 or Dhanmondi.', 'line' => 'House, road and village / street', 'line_help' => 'As clearly as you can, so the courier can find you.',
        'landmark' => 'Nearby landmark (optional)', 'note' => 'Note for the courier (optional)', 'postal' => 'Postal code (optional)',
        'errors' => ['limit_reached' => 'You can keep up to :max addresses. Remove one first.', 'phone_invalid' => 'Enter a valid Bangladesh mobile number, like 01712345678.', 'location_mismatch' => 'The division, district and upazila do not belong together. Please choose them again.', 'postal_invalid' => 'A postal code has 4 digits.', 'not_found' => 'That address was not found.'],
    ],
    'profile' => [
        'title' => 'My profile',
        'name' => 'Full name',
        'birth_date' => 'Date of birth (optional)',
        'birth_help' => 'Used only for birthday offers if you opt in below.',
        'marketing' => 'Send me offers and news by SMS and email. I can change this at any time.',
        'save' => 'Save profile',
        'saved' => 'Your profile was saved.',
        'language' => 'Language',
    ],
];
