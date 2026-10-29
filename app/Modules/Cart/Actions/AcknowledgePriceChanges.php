<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartRepository;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\PriceCatalog;

/** "I have seen the new prices": from now on the notice compares against today's prices. */
final class AcknowledgePriceChanges
{
    public function __construct(private readonly CartRepository $carts, private readonly PurchasableVariants $variants, private readonly PriceCatalog $prices) {}

    public function handle(CartOwner $owner): void
    {
        $cart = $this->carts->find($owner);
        if ($cart === null) {
            return;
        }

        Transactions::run(function () use ($cart): void {
            $items = CartItem::query()->where('cart_id', $cart->id)->lockForUpdate()->get();
            $details = $this->variants->details(array_values($items->pluck('variant_id')->map(fn ($v) => (int) $v)->all()), app()->getLocale());
            $quotes = $this->prices->quoteMany(array_map(fn ($d) => $d->taxClassCode, $details));

            foreach ($items as $item) {
                if (isset($quotes[$item->variant_id])) {
                    $item->forceFill(['added_price_minor' => $quotes[$item->variant_id]->price->amountMinor])->save();
                }
            }
        });
    }
}
