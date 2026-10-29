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

    /**
     * Quotes many variants in a fixed number of queries (cart, checkout). Same result as quote() for each; variants without a price are simply absent.
     *
     * @param  array<int, string>  $taxClassByVariant  variant id => tax class code
     * @return array<int, PriceQuote>
     */
    public function quoteMany(array $taxClassByVariant, ?CarbonInterface $at = null, string $listCode = 'retail'): array;

    /** @return list<int> variants whose displayed price changed in (since, until]: price edits plus sales that started or ended */
    public function changedVariantIds(CarbonInterface $since, CarbonInterface $until): array;

    /** @param  list<int>  $variantIds */
    public function allHaveRegularPrice(array $variantIds, string $listCode = 'retail'): bool;
}
