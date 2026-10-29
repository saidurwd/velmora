<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use DomainException;

/** `reason` is a key under pricing::coupons.reasons (shown to the shopper in their language). */
final class CouponException extends DomainException
{
    public function __construct(public readonly string $reason, public readonly string $couponCode = '')
    {
        parent::__construct($reason.($couponCode === '' ? '' : " [{$couponCode}]"));
    }
}
