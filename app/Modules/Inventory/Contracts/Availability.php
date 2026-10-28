<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use Carbon\CarbonInterface;

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

    /** @return list<int> variants whose stock rows changed in (since, until]: lets read models (storefront listing) catch up without hooking every stock action */
    public function changedVariantIds(CarbonInterface $since, CarbonInterface $until): array;
}
