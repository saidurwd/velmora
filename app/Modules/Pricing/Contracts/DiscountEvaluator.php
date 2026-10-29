<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\DTOs\DiscountRequest;

/** Decides which coupons and promotions apply to a cart, and for how much. Read-only: nothing is redeemed here. */
interface DiscountEvaluator
{
    public function evaluate(DiscountRequest $request): DiscountOutcome;
}
