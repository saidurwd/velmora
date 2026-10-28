<?php

declare(strict_types=1);

return [
    // How long a reservation holds stock before the expiry job releases it. Callers may pass their own (e.g. COD confirmation window).
    'default_reservation_ttl_seconds' => 1200, // 20 minutes
    'expire_batch_size' => 200,
];
