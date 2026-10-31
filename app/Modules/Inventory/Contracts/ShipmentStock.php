<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** Taking stock out of the warehouse when a parcel is made (ADR-013), and putting it back if the parcel is cancelled before it leaves. */
interface ShipmentStock
{
    /**
     * Commits the order's reserved stock for the shipped quantities only; the rest stays reserved. All lines or none; run inside the caller's transaction.
     *
     * @param  array<int, int>  $lines  variant id => quantity
     *
     * @throws \DomainException when the order does not hold that much stock
     */
    public function commit(string $orderRef, array $lines, string $shipmentRef, ?int $actorId): void;

    /** Puts a cancelled shipment's stock back into the order's reservation (one ledger row per movement). Repeating it is harmless. */
    public function revert(string $orderRef, string $shipmentRef, ?int $actorId): void;
}
