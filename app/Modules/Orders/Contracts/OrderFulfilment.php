<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\ShippableOrder;
use App\Modules\Platform\DTOs\Actor;

/**
 * What Shipping may ask of Orders. Every call runs inside the caller's transaction: `lock` takes the order row first, so a parcel being made and an
 * order being cancelled are applied one after the other, never together.
 */
interface OrderFulfilment
{
    /** Locks the order row and describes it. Null when there is no such order. */
    public function lock(string $orderPublicId): ?ShippableOrder;

    /**
     * Counts these quantities as shipped (conditional on never passing the quantity ordered), moves the fulfilment state and, for the first
     * parcel, the order to "processing". False (and nothing counted) when a quantity would pass what was ordered.
     *
     * @param  array<int, int>  $quantities  order item id => quantity
     */
    public function recordShipped(Actor $actor, int $orderId, array $quantities, string $reason): bool;

    /**
     * Takes the quantities of a parcel cancelled before it left off the shipped counts and moves the fulfilment state back.
     * False when the counts do not allow it.
     *
     * @param  array<int, int>  $quantities  order item id => quantity
     */
    public function recordUnshipped(Actor $actor, int $orderId, array $quantities, string $reason): bool;
}
