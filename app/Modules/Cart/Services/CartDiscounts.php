<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\CartCoupon;
use App\Modules\Catalog\Contracts\VariantClassification;
use App\Modules\Pricing\Contracts\DiscountEvaluator;
use App\Modules\Pricing\DTOs\DiscountLine;
use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\DTOs\DiscountRequest;

/** Asks the pricing module what the cart's codes and automatic promotions are worth right now. */
final class CartDiscounts
{
    public function __construct(private readonly DiscountEvaluator $evaluator, private readonly VariantClassification $classification) {}

    /**
     * @param  list<CartLineView>  $lines
     * @param  list<string>  $codes
     */
    public function evaluate(CartOwner $owner, array $lines, array $codes, string $locale): DiscountOutcome
    {
        $buyable = array_values(array_filter($lines, fn (CartLineView $l) => $l->problem !== 'unavailable' && $l->lineTotal !== null));
        $classes = $this->classification->forVariants(array_map(fn (CartLineView $l) => $l->variantId, $buyable));

        $discountLines = array_map(fn (CartLineView $l) => new DiscountLine($l->variantId, $classes[$l->variantId]['productId'] ?? 0, $classes[$l->variantId]['categoryIds'] ?? [], (int) $l->lineTotal?->amountMinor), $buyable);

        return $this->evaluator->evaluate(new DiscountRequest($discountLines, $codes, $owner->userId === null ? null : 'u:'.$owner->userId, $locale));
    }

    /** @return list<string> */
    public function codes(int $cartId): array
    {
        return array_values(CartCoupon::query()->where('cart_id', $cartId)->orderBy('id')->pluck('code')->map(fn ($c) => (string) $c)->all());
    }
}
