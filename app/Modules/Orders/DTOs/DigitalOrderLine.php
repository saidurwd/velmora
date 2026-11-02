<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** One digital line of an order, as DigitalProducts needs it. */
final readonly class DigitalOrderLine
{
    public function __construct(public int $orderItemId, public int $variantId, public int $quantity) {}
}
