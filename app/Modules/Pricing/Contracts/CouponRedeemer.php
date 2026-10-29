<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\Exceptions\CouponException;

/** Uses the coupons of an accepted order, atomically. Call it inside the order's own database transaction: a failure then undoes everything. */
interface CouponRedeemer
{
    /** @throws CouponException when a coupon ran out, expired or hit the per-customer limit between the cart and the order */
    public function redeem(DiscountOutcome $outcome, int $orderId, ?string $redeemer): void;
}
