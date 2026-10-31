<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use Carbon\CarbonImmutable;

/** What cash-on-delivery bookkeeping needs to know about an order, and nothing more. */
final readonly class CodOrderInfo
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public string $orderNumber,
        public int $amountMinor,
        public string $currency,
        public bool $isCod,
        public OrderStatus $status,
        public PaymentStatus $paymentStatus,
        public CarbonImmutable $placedAt,
        public bool $hasParcels = false,   // anything has been put on a parcel: the cash is then recorded parcel by parcel, as each is delivered
    ) {}
}
