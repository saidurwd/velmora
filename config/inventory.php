<?php

declare(strict_types=1);

return [
    // How long a reservation holds stock before the expiry job releases it. Callers may pass their own (e.g. COD confirmation window).
    'default_reservation_ttl_seconds' => 1200, // 20 minutes
    'expire_batch_size' => 200,

    // Stock files: the most rows one file may have, and how long a previewed file can still be applied (the books move on, so an old preview is refused).
    'imports' => ['max_rows' => 1000, 'preview_hours' => 24],
];
