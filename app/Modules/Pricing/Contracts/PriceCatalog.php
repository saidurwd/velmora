<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\PriceQuote;
use App\Modules\Pricing\Exceptions\PricingException;
use Carbon\CarbonInterface;

/** The public face of Pricing for other modules (Catalog publish rule, Cart, Checkout, API). */
interface PriceCatalog
{
    /** @throws PricingException when the variant has no price */
    public function quote(int $variantId, string $taxClassCode, ?CarbonInterface $at = null, string $listCode = 'retail'): PriceQuote;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, array{amount_minor: int, compare_at_minor: int|null}> regular prices keyed by variant id (variants without a price are absent)
     */
    public function regularPrices(array $variantIds, string $listCode = 'retail'): array;

    /** @param  list<int>  $variantIds */
    public function allHaveRegularPrice(array $variantIds, string $listCode = 'retail'): bool;
}
