<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** One booked-service line of an order, as Bookings needs it. */
final readonly class ServiceOrderLine
{
    public function __construct(public int $orderItemId, public int $variantId) {}
}
