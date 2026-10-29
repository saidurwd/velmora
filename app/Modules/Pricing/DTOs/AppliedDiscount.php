<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

final readonly class AppliedDiscount
{
    /** @param  'coupon'|'promotion'  $kind */
    public function __construct(public string $kind, public ?string $code, public string $label, public int $amountMinor, public bool $freeShipping = false) {}
}
