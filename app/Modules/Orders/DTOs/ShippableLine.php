<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** One order line as fulfilment sees it. */
final readonly class ShippableLine
{
    public function __construct(
        public int $orderItemId,
        public int $variantId,
        public string $productType,
        public string $name,
        public string $sku,
        public int $quantity,
        public int $fulfilledQty,
        public int $returnedQty,
        public int $lineTotalMinor,
    ) {}

    public function remaining(): int
    {
        return $this->quantity - $this->fulfilledQty;
    }
}
