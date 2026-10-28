<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** What other modules (Cart, Storefront, API) may ask about stock. Never the tables. */
interface Availability
{
    /** Units that can still be promised: Σ over active locations of (on_hand − reserved), never negative. */
    public function forVariant(int $variantId): int;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int> variant id => available units (0 when unknown)
     */
    public function forVariants(array $variantIds): array;
}
