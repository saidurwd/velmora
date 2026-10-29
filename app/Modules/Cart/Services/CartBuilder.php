<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\DTOs\CartView;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Contracts\PriceCatalog;

/** Builds the cart view from live data in a fixed number of queries: variants, prices (incl. sales) and stock are each read once. */
final class CartBuilder implements CartReader
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PurchasableVariants $variants,
        private readonly PriceCatalog $prices,
        private readonly Availability $availability,
    ) {}

    public function view(CartOwner $owner, string $locale): CartView
    {
        $cart = $this->carts->find($owner);
        if ($cart === null) {
            return new CartView(null, [], Money::zero(), 0);
        }

        $items = CartItem::query()->where('cart_id', $cart->id)->orderBy('id')->get();
        $ids = array_values($items->pluck('variant_id')->map(fn ($v) => (int) $v)->all());
        $details = $this->variants->details($ids, $locale);
        $quotes = $this->prices->quoteMany(array_map(fn ($d) => $d->taxClassCode, array_filter($details, fn ($d) => $d->isPurchasable)));
        $stock = $this->availability->forVariants($ids);

        $lines = [];
        $subtotal = Money::zero();
        $count = 0;

        foreach ($items as $item) {
            $d = $details[$item->variant_id] ?? null;
            $q = $quotes[$item->variant_id] ?? null;
            $count += $item->quantity;

            if ($d === null || ! $d->isPurchasable || $q === null) {
                $lines[] = new CartLineView($d->publicId ?? '', $item->variant_id, $d->sku ?? '', $d->productName ?? '', $d?->optionsLabel, $d->productSlug ?? '', $d?->imageUrl, $d->productType ?? 'physical', $item->quantity, $d->minQty ?? 1, $d?->maxQty, $d->qtyStep ?? 1, null, null, null, 'unavailable', null);

                continue;
            }

            $available = $d->trackInventory ? ($stock[$item->variant_id] ?? 0) : null;
            $line = $q->price->multiply($item->quantity);
            $subtotal = $subtotal->add($line);

            $lines[] = new CartLineView(
                $d->publicId, $item->variant_id, $d->sku, $d->productName, $d->optionsLabel, $d->productSlug, $d->imageUrl, $d->productType, $item->quantity,
                $d->minQty, $d->maxQty, $d->qtyStep, $q->price, $line,
                $item->added_price_minor !== $q->price->amountMinor ? Money::of($item->added_price_minor) : null,
                $available !== null && $available < $item->quantity ? 'insufficient_stock' : null, $available,
            );
        }

        return new CartView($cart->public_id, $lines, $subtotal, $count);
    }

    public function itemCount(CartOwner $owner): int
    {
        $cart = $this->carts->find($owner);

        return $cart === null ? 0 : (int) CartItem::query()->where('cart_id', $cart->id)->sum('quantity');
    }
}
