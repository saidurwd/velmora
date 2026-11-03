<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** One booked-service line of an order, as Bookings needs it: which order it is in and what was paid for the line (its total after discounts, with tax). */
final readonly class ServiceOrderLine
{
    public function __construct(public int $orderItemId, public int $variantId, public int $orderId = 0, public int $paidMinor = 0) {}
}
