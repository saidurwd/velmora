<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use DomainException;

/** Why a coupon or promotion could not be saved. `reason` is a key under pricing::promotions.errors. */
final class PromotionException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
