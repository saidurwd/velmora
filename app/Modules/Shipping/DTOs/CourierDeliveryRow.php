<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** How one courier did on the parcels handed to it in a period. `deliverySeconds` is the total time from handover to delivery over the `delivered` parcels. */
final readonly class CourierDeliveryRow
{
    public function __construct(
        public string $courier,
        public int $parcels,
        public int $delivered,
        public int $failed,
        public int $returned,
        public int $inTransit,
        public int $failedAttempts,
        public int $deliverySeconds,
        public int $codMinor,
    ) {}
}
