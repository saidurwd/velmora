<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartFiller;
use App\Modules\Cart\DTOs\CartFillResult;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\Availability;

final class CartLineFiller implements CartFiller
{
    public function __construct(private readonly AddToCart $add, private readonly CartRepository $carts, private readonly PurchasableVariants $variants, private readonly Availability $availability) {}

    public function add(CartOwner $owner, int $variantId, int $quantity, string $locale): CartFillResult
    {
        try {
            $this->add->handle($owner, $variantId, $quantity);

            return new CartFillResult('added', $quantity, $quantity);
        } catch (CartException $e) {
            if (! in_array($e->reason, ['insufficient_stock', 'max_qty'], true)) {
                return new CartFillResult('skipped', $quantity, 0, $e->reason, $e->params);
            }

            return $this->partial($owner, $variantId, $quantity, $locale, $e);
        }
    }

    /**
     * Adds as much as fits: the room left under the stock and the per-line limits together (on top of what is already in the cart), in whole steps of the item.
     * Skips the line when not even the smallest order fits.
     */
    private function partial(CartOwner $owner, int $variantId, int $quantity, string $locale, CartException $why): CartFillResult
    {
        $variant = $this->variants->details([$variantId], $locale)[$variantId] ?? null;
        if ($variant === null) {
            return new CartFillResult('skipped', $quantity, 0, 'unavailable');
        }
        $cart = $this->carts->find($owner);
        $inCart = $cart === null ? 0 : (int) CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->value('quantity');   // unique (cart_id, variant_id)

        $limits = [min($variant->maxQty ?? CartRules::MAX_PER_LINE, CartRules::MAX_PER_LINE)];
        if ($variant->trackInventory) {
            $limits[] = $this->availability->forVariant($variantId);
        }
        $fits = min($limits) - $inCart;
        $step = max(1, $variant->qtyStep);
        // the cart wants quantities of min, min + step, min + 2*step ...: a first order must reach the minimum; later ones keep the step
        $fits = $inCart > 0 ? $fits - ($fits % $step) : ($fits >= $variant->minQty ? $fits - (($fits - $variant->minQty) % $step) : 0);
        $toAdd = min($fits, $quantity);
        if ($toAdd < 1) {
            return new CartFillResult('skipped', $quantity, 0, $why->reason, $why->params);
        }

        try {
            $this->add->handle($owner, $variantId, $toAdd);
        } catch (CartException $e) {
            return new CartFillResult('skipped', $quantity, 0, $e->reason, $e->params);
        }

        return new CartFillResult('partial', $quantity, $toAdd, $why->reason, $why->params);
    }
}
