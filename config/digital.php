<?php

declare(strict_types=1);

return [
    // The private bucket (config/filesystems.php): never public; files leave it only through short-lived signed addresses.
    'disk' => env('DIGITAL_DISK', 'private'),

    // Staff upload limit (megabytes). The PHP limits (upload_max_filesize, post_max_size) must be at least this big on the server; bigger files are a later feature.
    'max_upload_mb' => (int) env('DIGITAL_MAX_UPLOAD_MB', 200),

    // What may be uploaded: extension => the content types the file's own bytes may show. The name is never trusted; the content must match.
    'allowed' => [
        'pdf' => ['application/pdf'],
        'epub' => ['application/epub+zip', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'mp3' => ['audio/mpeg'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ],

    // Defaults for a new entitlement (settings digital.download_limit / digital.link_ttl_seconds override them; a product may set its own).
    'download_limit' => (int) env('DIGITAL_DOWNLOAD_LIMIT', 5),
    'link_ttl_seconds' => (int) env('DIGITAL_LINK_TTL_SECONDS', 60),
];
