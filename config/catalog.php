<?php

declare(strict_types=1);

return [
    'media' => [
        'disk' => env('CATALOG_MEDIA_DISK', 'media'),   // public bucket (BD-05)
        'max_upload_bytes' => 10 * 1024 * 1024,
        'min_dimension' => 200,                          // px, shortest side
        'max_dimension' => 8000,                         // px, longest side
        'max_pixels' => 40_000_000,                      // decompression-bomb guard, checked BEFORE decoding
        'master_width' => 2000,                          // the one stored "original" is re-encoded and capped at this
        'widths' => [320, 640, 960, 1280, 1600],         // responsive srcset sizes
        'webp_quality' => 80,
        'avif_quality' => 60,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
