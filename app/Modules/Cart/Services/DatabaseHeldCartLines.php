<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\HeldCartLines;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DomainException;

final class DatabaseHeldCartLines implements HeldCartLines
{
    public function __construct(private readonly CartRepository $carts, private readonly PurchasableVariants $variants, private readonly PriceCatalog $prices) {}

    public function put(CartOwner $owner, int $variantId, string $holdRef): void
    {
        $variant = $this->variants->details([$variantId], app()->getLocale())[$variantId] ?? throw new CartException('unavailable');
        try {
            $unit = $this->prices->quote($variantId, $variant->taxClassCode)->price->amountMinor;
        } catch (DomainException) {
            throw new CartException('unavailable');
        }

        Transactions::run(function () use ($owner, $variantId, $holdRef, $unit): void {
            $cart = $this->carts->findOrCreate($owner);
            $cart->newQuery()->whereKey($cart->id)->lockForUpdate()->first();
            $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->first();
            if ($item === null && CartItem::query()->where('cart_id', $cart->id)->count() >= CartRules::MAX_LINES) {
                throw new CartException('too_many_lines', ['max' => CartRules::MAX_LINES]);
            }
            $item === null
                ? CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variantId, 'quantity' => 1, 'added_price_minor' => $unit, 'hold_ref' => $holdRef])
                : $item->forceFill(['quantity' => 1, 'added_price_minor' => $unit, 'hold_ref' => $holdRef])->save();
            $this->carts->touch($cart);
        });
    }

    public function refFor(CartOwner $owner, int $variantId): ?string
    {
        $cart = $this->carts->find($owner);

        return $cart === null ? null : CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->value('hold_ref');
    }
}
