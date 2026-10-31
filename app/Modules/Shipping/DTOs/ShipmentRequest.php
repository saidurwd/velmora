<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Everything a courier needs to collect and deliver one parcel, and nothing more (no email, no order history). */
final readonly class ShipmentRequest
{
    public function __construct(
        public string $shipmentPublicId,
        public string $orderNumber,
        public string $recipientName,
        public string $recipientPhone,
        public string $addressLine,
        public string $district,
        public ?string $area,
        public ?string $postalCode,
        public int $itemCount,
        public int $weightGrams,
        public int $codAmountMinor,
        public string $currency,
        public ?string $note = null,
    ) {}
}
