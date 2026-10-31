<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** What the courier gave back when the parcel was booked. A manual courier gives nothing: staff add the tracking code later. */
final readonly class CourierBooking
{
    public function __construct(
        public ?string $consignmentId = null,
        public ?string $trackingCode = null,
        public ?string $trackingUrl = null,
    ) {}
}
