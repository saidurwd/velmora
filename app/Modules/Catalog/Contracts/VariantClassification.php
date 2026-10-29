<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** Which product and categories a variant belongs to: what coupon and promotion scopes are matched against. */
interface VariantClassification
{
    /**
     * Categories include every ancestor, so a coupon for "Clothing" also matches a product filed under "Clothing › Shirts".
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{productId: int, categoryIds: list<int>}> keyed by variant id
     */
    public function forVariants(array $variantIds): array;
}
