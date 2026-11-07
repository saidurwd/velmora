<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\VariantGrouping;

/** Read-only, for the Reporting module. Each product has one primary category, so a total split by category counts every sale once. */
interface ProductGroupings
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, VariantGrouping> keyed by variant id (a variant that no longer exists is left out)
     */
    public function forVariants(array $variantIds, string $locale): array;
}
