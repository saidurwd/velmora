<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Platform\DTOs\Money;

/** One way to get the order delivered, priced. */
final readonly class ShippingOption
{
    public function __construct(
        public string $methodCode,
        public string $name,
        public ?string $deliveryEstimate,
        public Money $price,
        public bool $isFree,
        public bool $codAvailable,
        public Money $codFee,
    ) {}
}
