<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** Goods coming back into the warehouse after inspection. Every unit gets a ledger row, so it can be followed from receipt through the sale to its return. */
interface ReturnStock
{
    /**
     * Restock puts the units back on sale where they left from; quarantine keeps them in the returns location, not for sale; write-off brings them in and
     * out again at once, so the ledger shows them coming back and being destroyed. Runs inside the caller's transaction.
     *
     * @param  string  $outcome  restock, quarantine or write_off
     * @param  string|null  $shipmentRef  the parcel they went out in (they go back to the place they left from)
     */
    public function receive(string $returnRef, int $variantId, int $quantity, string $outcome, ?string $shipmentRef, ?int $actorId): void;
}
