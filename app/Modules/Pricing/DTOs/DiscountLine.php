<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/** A cart line as discounts see it: what it is (product, categories) and what it costs now (sale price applied, before any discount). */
final readonly class DiscountLine
{
    /** @param  list<int>  $categoryIds  including ancestors */
    public function __construct(public int $variantId, public int $productId, public array $categoryIds, public int $totalMinor) {}
}
