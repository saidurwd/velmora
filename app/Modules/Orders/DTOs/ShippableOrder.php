<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Orders\Enums\FulfillmentStatus;

/** What making a parcel needs to know about an order, taken while the order row is locked. */
final readonly class ShippableOrder
{
    /**
     * @param  list<ShippableLine>  $lines
     * @param  string|null  $notReadyReason  null when parcels may be made now; otherwise a reason key (order_not_confirmed, order_cancelled, order_under_review, order_not_paid)
     */
    public function __construct(
        public int $orderId,
        public string $publicId,
        public string $number,
        public string $paymentMethodCode,
        public bool $paymentOutstanding,
        public FulfillmentStatus $fulfillment,
        public int $grandTotalMinor,
        public string $currency,
        public string $contactName,
        public string $contactPhone,
        public string $addressLine,
        public string $district,
        public ?string $area,
        public ?string $postalCode,
        public array $lines,
        public ?string $notReadyReason,
    ) {}
}
