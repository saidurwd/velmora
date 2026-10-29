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

/** Sets a line to an exact quantity; 0 removes it. */
final class SetCartQuantity
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartRules $rules,
        private readonly PurchasableVariants $variants,
        private readonly Availability $availability,
        private readonly RemoveFromCart $remove,
    ) {}

    /** @throws CartException */
    public function handle(CartOwner $owner, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove->handle($owner, $variantId);

            return;
        }

        $variant = $this->variants->details([$variantId], app()->getLocale())[$variantId] ?? throw new CartException('unavailable');
        $available = $variant->trackInventory ? $this->availability->forVariant($variantId) : null;

        Transactions::run(function () use ($owner, $variantId, $quantity, $variant, $available): void {
            $cart = $this->carts->find($owner) ?? throw new CartException('not_in_cart');
            $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->lockForUpdate()->first() ?? throw new CartException('not_in_cart');

            $this->rules->assertQuantity($variant, $quantity, $available);
            $item->forceFill(['quantity' => $quantity])->save();
            $this->carts->touch($cart);
        });
    }
}
