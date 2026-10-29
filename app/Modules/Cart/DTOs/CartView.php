<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use App\Modules\Platform\DTOs\Money;

/** The cart as the customer sees it, recomputed from live prices and stock on every request. Never trusted from the browser. */
final readonly class CartView
{
    /** @param  list<CartLineView>  $lines */
    public function __construct(public ?string $cartPublicId, public array $lines, public Money $subtotal, public int $itemCount) {}

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
