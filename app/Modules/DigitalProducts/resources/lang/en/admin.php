<?php

declare(strict_types=1);

return [
    'menu' => ['assets' => 'Digital files', 'entitlements' => 'Customer downloads', 'policies' => 'Refund policies'],
    'title' => 'Digital files', 'help' => 'The downloadable files behind digital products. Each upload becomes a new version; customers get the newest. Files are kept private: nothing here gives out the file itself.',
    'search' => 'SKU or product name', 'find' => 'Find', 'none_found' => 'No matching digital product.', 'back' => 'Back',
    'cols' => ['sku' => 'SKU', 'product' => 'Product', 'versions' => 'Versions', 'version' => 'Version', 'file' => 'File', 'size' => 'Size', 'checksum' => 'Checksum (SHA-256)', 'uploaded' => 'Uploaded'],
    'upload_title' => 'Upload a new version', 'upload_help' => 'Allowed: :types. At most :mb MB. The file\'s content is checked, not just its name.', 'file' => 'File', 'upload_btn' => 'Upload',
    'uploaded' => 'Version :version was uploaded.', 'versions' => 'Versions', 'no_versions' => 'No file has been uploaded for this product yet.', 'current' => 'Current', 'size_kb' => ':n KB',
    'keys' => [
        'title' => 'Licence keys', 'help' => 'If this product is sold with a licence key, add the keys here: a text file with one key per line (up to :max). Each customer gets one key per unit bought. Keys are kept encrypted and are never shown here.',
        'available' => 'Available', 'allocated' => 'Given out', 'revoked' => 'Withdrawn', 'waiting' => 'Owed, waiting', 'file' => 'File of keys (.txt or .csv)', 'import_btn' => 'Add keys',
        'short' => 'There are :waiting keys owed to customers and only :available available. Add more keys; the customers who are waiting get them automatically, oldest first.',
        'imported' => ':added keys added, :duplicates already there, :invalid not valid. :filled keys went straight to customers who were waiting.',
    ],
    'yes' => 'Yes', 'no' => 'No',
    'policies' => [
        'title' => 'Refund policies', 'new' => 'New policy', 'edit' => 'Edit policy', 'help' => 'What a customer reads, before they buy, about getting money back for a digital product. The rule also tells staff what to expect; the refund itself is decided in Payments.',
        'cols' => ['policy' => 'Policy', 'rule' => 'Rule', 'revoke' => 'Takes downloads away on a full refund'], 'default' => 'Default', 'days_n' => ':n days',
        'rules' => ['non_refundable' => 'Never refundable', 'until_first_use' => 'Refundable until the first download or look at the keys', 'within_days' => 'Refundable for a number of days'],
        'rule' => 'Rule', 'days' => 'Days', 'days_help' => 'Only for “a number of days”.', 'revoke' => 'A fully refunded order loses its downloads and keys', 'make_default' => 'Use this policy for products that have none of their own',
        'words_title' => 'Title shown to customers', 'words_text' => 'Text shown to customers (English is needed; other languages fall back to it)', 'save' => 'Save policy', 'saved' => 'The policy was saved.',
        'for_product' => 'Refund policy of this product', 'choose' => 'Policy', 'use_default' => 'Use the shop\'s default policy', 'apply' => 'Save choice', 'chosen' => 'The product\'s refund policy was saved.',
    ],
    'entitlements' => [
        'title' => 'Customer downloads', 'help' => 'Find a customer\'s downloads by their order number. See what they have used, what the refund policy says, and give them more time or downloads, or take it away.',
        'order' => 'Order number', 'no_order' => 'No order has that number.', 'none' => 'This order has nothing to download.', 'status' => ['active' => 'Active', 'revoked' => 'Withdrawn'],
        'downloads' => 'Downloads used', 'unlimited' => 'no limit', 'expires' => 'Available until', 'never' => 'No end date', 'granted' => 'Granted', 'first_used' => 'First used', 'not_yet' => 'Not yet', 'keys' => 'Licence keys given / owed', 'revoked_why' => 'Withdrawn because',
        'refundable' => 'Refundable', 'not_refundable' => 'Not refundable', 'advice' => 'This is the policy\'s view. The refund itself is decided in Payments.',
        'reason' => ['not_refundable' => 'this product is never refundable', 'unused' => 'it has not been downloaded or looked at yet', 'used' => 'it has already been downloaded or its keys looked at', 'within_window' => 'it is still inside the refund period', 'window_closed' => 'the refund period has passed'],
        'action' => 'Change', 'extend' => 'Add days of access', 'add_downloads' => 'Add downloads', 'reset' => 'Start the count again', 'amount' => 'How many', 'apply' => 'Apply', 'reason_label' => 'Why take it away?', 'revoke' => 'Take away',
        'changed' => 'The change was made and recorded.', 'revoked' => 'The download was taken away.',
    ],
];
