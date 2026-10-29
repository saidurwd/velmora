<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\PurchasableVariant;

interface PurchasableVariants
{
    /**
     * @param  list<int>  $ids
     * @return array<int, PurchasableVariant> keyed by variant id; variants that no longer exist are absent,
     *                                        variants that exist but cannot be bought now (unpublished, inactive) have isPurchasable = false
     */
    public function details(array $ids, string $locale): array;

    public function idByPublicId(string $publicId): ?int;
}
