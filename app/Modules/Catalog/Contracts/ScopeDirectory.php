<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** What staff pick when they limit a coupon to certain products or categories. */
interface ScopeDirectory
{
    /**
     * @param  list<string>  $skus  as typed (any case)
     * @return array<string, int> upper-case SKU => product id, for the SKUs that exist
     */
    public function productIdsBySku(array $skus): array;

    /**
     * @param  list<int>  $productIds
     * @return array<int, string> product id => the SKU to show for it
     */
    public function skusForProducts(array $productIds): array;

    /**
     * Every category, parents before their children.
     *
     * @return list<array{id: int, name: string, depth: int}>
     */
    public function categoryOptions(string $locale): array;
}
