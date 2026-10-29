<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Pricing\DTOs\PricingContext;
use App\Modules\Pricing\Exceptions\PricingException;

/** The single place an order total is computed. Cart pages, checkout and the API all call this: no duplicated pricing logic. */
interface CartPricer
{
    /** @throws PricingException when a line has no price */
    public function price(PricingContext $context): PriceBreakdown;
}
