<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\DTOs\DiscountOutcome;

/** The cart as the customer sees it, recomputed from live prices and stock on every request. Never trusted from the browser. */
final readonly class CartView
{
    /** @param  list<CartLineView>  $lines */
    public function __construct(public ?string $cartPublicId, public array $lines, public Money $subtotal, public int $itemCount, public ?DiscountOutcome $discounts = null) {}

    public function discountTotal(): Money
    {
        return Money::of($this->discounts?->totalMinor() ?? 0);
    }

    public function freeShipping(): bool
    {
        return $this->discounts !== null && $this->discounts->freeShipping;
    }

    /** Goods after discounts (never below zero); delivery, fees and tax come at checkout. */
    public function goodsTotal(): Money
    {
        return Money::of(max(0, $this->subtotal->amountMinor - $this->discountTotal()->amountMinor));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasProblems(): bool
    {
        return array_filter($this->lines, fn (CartLineView $l) => $l->problem !== null) !== [];
    }

    public function hasPriceChanges(): bool
    {
        return array_filter($this->lines, fn (CartLineView $l) => $l->priceChangedFrom !== null) !== [];
    }

    public function physicalOnly(): bool
    {
        return $this->lines !== [] && array_filter($this->lines, fn (CartLineView $l) => $l->productType !== 'physical') === [];
    }
}
