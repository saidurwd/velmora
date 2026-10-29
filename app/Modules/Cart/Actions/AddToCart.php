<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartRepository;
use App\Modules\Cart\Services\CartRules;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DomainException;

/** Adds a quantity of a variant (increasing an existing line). Prices are read here, never accepted from the browser. */
final class AddToCart
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartRules $rules,
        private readonly PurchasableVariants $variants,
        private readonly Availability $availability,
        private readonly PriceCatalog $prices,
    ) {}

    /** @throws CartException */
    public function handle(CartOwner $owner, int $variantId, int $quantity): void
    {
        $variant = $this->variants->details([$variantId], app()->getLocale())[$variantId] ?? throw new CartException('unavailable');
        $available = $variant->trackInventory ? $this->availability->forVariant($variantId) : null;

        try {
            $unit = $this->prices->quote($variantId, $variant->taxClassCode)->price->amountMinor;
        } catch (DomainException) {
            throw new CartException('unavailable'); // no price: cannot be sold
        }

        Transactions::run(function () use ($owner, $variantId, $quantity, $variant, $available, $unit): void {
            $cart = $this->carts->findOrCreate($owner);
            $cart->newQuery()->whereKey($cart->id)->lockForUpdate()->first(); // two taps on "add" at once are applied one after the other

            $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->first();
            if ($item === null && CartItem::query()->where('cart_id', $cart->id)->count() >= CartRules::MAX_LINES) {
                throw new CartException('too_many_lines', ['max' => CartRules::MAX_LINES]);
            }

            $total = ($item->quantity ?? 0) + $quantity;
            $this->rules->assertQuantity($variant, $total, $available);

            $item === null
                ? CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variantId, 'quantity' => $total, 'added_price_minor' => $unit])
                : $item->forceFill(['quantity' => $total])->save();

            $this->carts->touch($cart);
        });
    }
}
