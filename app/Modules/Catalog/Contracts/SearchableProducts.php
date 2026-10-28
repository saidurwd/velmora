<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\SearchDocument;

/** What the Search module may know about a product (it cannot read catalog tables). */
interface SearchableProducts
{
    /** @return SearchDocument|null null when the product is not live on the storefront */
    public function document(int $productId): ?SearchDocument;

    /** @return list<int> ids of every live product */
    public function liveProductIds(): array;
}
