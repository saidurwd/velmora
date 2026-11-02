<?php

declare(strict_types=1);

return [
    'menu' => ['assets' => 'Digital files'],
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
];
