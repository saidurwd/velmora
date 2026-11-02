<?php

declare(strict_types=1);

return [
    'menu' => ['assets' => 'Digital files'],
    'title' => 'Digital files', 'help' => 'The downloadable files behind digital products. Each upload becomes a new version; customers get the newest. Files are kept private: nothing here gives out the file itself.',
    'search' => 'SKU or product name', 'find' => 'Find', 'none_found' => 'No matching digital product.', 'back' => 'Back',
    'cols' => ['sku' => 'SKU', 'product' => 'Product', 'versions' => 'Versions', 'version' => 'Version', 'file' => 'File', 'size' => 'Size', 'checksum' => 'Checksum (SHA-256)', 'uploaded' => 'Uploaded'],
    'upload_title' => 'Upload a new version', 'upload_help' => 'Allowed: :types. At most :mb MB. The file\'s content is checked, not just its name.', 'file' => 'File', 'upload_btn' => 'Upload',
    'uploaded' => 'Version :version was uploaded.', 'versions' => 'Versions', 'no_versions' => 'No file has been uploaded for this product yet.', 'current' => 'Current', 'size_kb' => ':n KB',
];
