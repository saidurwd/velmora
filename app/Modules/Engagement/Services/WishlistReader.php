<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Support\Fmt;
use App\Modules\Pricing\Contracts\PriceCatalog;
use Illuminate\Support\Facades\DB;

/**
 * What the customer's wishlist and waiting alerts page shows: names, current prices and stock, read for the whole list in a fixed number of queries.
 * Always for the one user asked about.
 */
final class WishlistReader
{
    public function __construct(private readonly PurchasableVariants $variants, private readonly Availability $availability, private readonly PriceCatalog $prices) {}

    /** @return list<array{variant: string, name: string, image: string|null, url: string, price: string|null, state: string, alert: bool, canAlert: bool}> */
    public function items(int $userId, string $locale): array
    {
        $ids = array_values(DB::table('wishlist_items')->where('user_id', $userId)->orderByDesc('id')->pluck('variant_id')->map(fn ($v): int => (int) $v)->all());   // unique (user_id, variant_id)
        if ($ids === []) {
            return [];
        }
        $details = $this->variants->details($ids, $locale);
        $stock = $this->availability->forVariants($ids);
        $quotes = $this->prices->quoteMany(array_map(fn ($d) => $d->taxClassCode, array_filter($details, fn ($d) => $d->isPurchasable)));
        $waiting = DB::table('stock_alerts')->where('user_id', $userId)->where('status', 'pending')->whereIn('variant_id', $ids)->pluck('variant_id')->map(fn ($v): int => (int) $v)->all();

        $rows = [];
        foreach ($ids as $id) {
            $d = $details[$id] ?? null;
            if ($d === null) {
                continue;   // gone from the shop
            }
            $state = ! $d->isPurchasable ? 'unavailable' : (! $d->trackInventory || ($stock[$id] ?? 0) > 0 ? 'in_stock' : 'out_of_stock');
            $rows[] = [
                'variant' => $d->publicId, 'name' => trim($d->productName.($d->optionsLabel !== null ? ' ('.$d->optionsLabel.')' : '')), 'image' => $d->imageUrl,
                'url' => route('storefront.product', ['locale' => $locale, 'slug' => $d->productSlug]),
                'price' => isset($quotes[$id]) ? Fmt::money($quotes[$id]->price) : null, 'state' => $state, 'alert' => in_array($id, $waiting, true), 'canAlert' => $state === 'out_of_stock',
            ];
        }

        return $rows;
    }

    /** @return list<array{variant: string, name: string, url: string, since: string}> alerts still waiting */
    public function waitingAlerts(int $userId, string $locale): array
    {
        $alerts = DB::table('stock_alerts')->where('user_id', $userId)->where('status', 'pending')->orderByDesc('id')->limit(100)->get(['variant_id', 'created_at']);   // unique (user_id, variant_id)
        $details = $this->variants->details(array_values($alerts->pluck('variant_id')->map(fn ($v): int => (int) $v)->all()), $locale);

        $rows = [];
        foreach ($alerts as $alert) {
            $d = $details[(int) $alert->variant_id] ?? null;
            if ($d !== null) {
                $rows[] = ['variant' => $d->publicId, 'name' => trim($d->productName.($d->optionsLabel !== null ? ' ('.$d->optionsLabel.')' : '')), 'url' => route('storefront.product', ['locale' => $locale, 'slug' => $d->productSlug]), 'since' => (string) $alert->created_at];
            }
        }

        return $rows;
    }
}
