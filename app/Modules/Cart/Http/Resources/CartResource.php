<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Resources;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartView;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Http\Resources\MoneyData;

/** The cart as the app shows it: recomputed from live prices and stock on every request, never trusted from the app. */
final class CartResource
{
    public const LINE = CartPresenter::LINE;

    public const TYPE = CartPresenter::TYPE;

    /** @return array<string, mixed> */
    public static function make(CartView $cart, ?string $token): array
    {
        $out = $cart->discounts;

        return [
            'token' => $token,
            'lines' => array_map(fn (CartLineView $l): array => [
                'variant_id' => $l->variantPublicId,
                'sku' => $l->sku,
                'name' => $l->name,
                'options' => $l->optionsLabel,
                'product_slug' => $l->productSlug,
                'image' => $l->imageUrl,
                'type' => $l->productType,
                'quantity' => $l->quantity,
                'min_qty' => $l->minQty,
                'max_qty' => $l->maxQty,
                'qty_step' => $l->qtyStep,
                'unit_price' => $l->unitPrice === null ? null : MoneyData::from($l->unitPrice),
                'line_total' => $l->lineTotal === null ? null : MoneyData::from($l->lineTotal),
                'price_changed_from' => $l->priceChangedFrom === null ? null : MoneyData::from($l->priceChangedFrom),
                'problem' => $l->problem,
                'available' => $l->available,
            ], $cart->lines),
            'item_count' => $cart->itemCount,
            'subtotal' => MoneyData::from($cart->subtotal),
            'discount_total' => MoneyData::from($cart->discountTotal()),
            'goods_total' => MoneyData::from($cart->goodsTotal()),
            'free_shipping' => $cart->freeShipping(),
            'discounts' => array_map(fn ($a): array => ['kind' => $a->kind, 'code' => $a->code, 'label' => $a->label, 'amount' => MoneyData::from(Money::of($a->amountMinor, $cart->subtotal->currency)), 'free_shipping' => $a->freeShipping], $out->applied ?? []),
            'rejected_codes' => array_map(fn (array $r): array => ['code' => $r['code'], 'reason' => $r['reason']], $out->rejected ?? []),
            'coupons' => $out?->acceptedCodes() ?? [],
            'has_problems' => $cart->hasProblems(),
            'has_price_changes' => $cart->hasPriceChanges(),
        ];
    }
}
