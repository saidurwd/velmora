<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Contracts;

use DomainException;

/** What checkout may ask of the booked times. */
interface BookingHolds
{
    /**
     * Takes the held time over for an order line, in the caller's transaction: it must still be held, not run out, not already someone's order, and be for this
     * product. The hold is then kept for the payment window.
     *
     * @throws DomainException when it cannot be claimed (the caller then does not place the order)
     */
    public function claim(string $holdRef, int $variantId, int $orderItemId): void;
}
