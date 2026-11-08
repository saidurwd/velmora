<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/** One coupon used on one order, and how much it took off. */
final readonly class CouponUse
{
    public function __construct(public string $code, public int $orderId, public int $discountMinor) {}
}
