<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\VariantSummary;

interface VariantDirectory
{
    /** @return list<VariantSummary> best matches for a SKU fragment or product name (staff search box) */
    public function search(string $term, int $limit = 20): array;

    public function findBySku(string $sku): ?VariantSummary;

    /**
     * @param  list<int>  $ids
     * @return array<int, VariantSummary> keyed by variant id
     */
    public function findMany(array $ids): array;
}
