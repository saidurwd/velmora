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

    /** @param  list<int>  $variantIds */
    public function allHaveRegularPrice(array $variantIds, string $listCode = 'retail'): bool;
}
