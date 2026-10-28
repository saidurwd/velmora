<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\DTOs\Money;
use Illuminate\Support\Facades\DB;

/** Read side of the price screens (staff only): current regular prices and sale windows. */
final class PriceAdminReader
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, array{amount: Money, compare_at: Money|null}> keyed by variant id
     */
    public function regularPrices(array $variantIds, string $listCode = 'retail'): array
    {
        $rows = DB::table('prices')->join('price_lists', 'price_lists.id', '=', 'prices.price_list_id')
            ->where('price_lists.code', $listCode)->whereIn('prices.variant_id', $variantIds)
            ->get(['prices.variant_id', 'prices.amount_minor', 'prices.compare_at_minor', 'prices.currency']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->variant_id] = [
                'amount' => Money::of((int) $r->amount_minor, (string) $r->currency),
                'compare_at' => $r->compare_at_minor === null ? null : Money::of((int) $r->compare_at_minor, (string) $r->currency),
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, amount: Money, starts_at: string, ends_at: string}> sales not yet finished, soonest first (UTC strings) */
    public function upcomingSales(int $variantId, string $listCode = 'retail'): array
    {
        return array_values(DB::table('price_sales')->join('price_lists', 'price_lists.id', '=', 'price_sales.price_list_id')
            ->where('price_lists.code', $listCode)->where('price_sales.variant_id', $variantId)->where('price_sales.ends_at', '>', now('UTC'))
            ->orderBy('price_sales.starts_at')
            ->get(['price_sales.id', 'price_sales.amount_minor', 'price_sales.currency', 'price_sales.starts_at', 'price_sales.ends_at'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'amount' => Money::of((int) $s->amount_minor, (string) $s->currency), 'starts_at' => (string) $s->starts_at, 'ends_at' => (string) $s->ends_at])
            ->all());
    }

    public function saleBelongsToVariant(int $saleId, int $variantId): bool
    {
        return DB::table('price_sales')->where('id', $saleId)->where('variant_id', $variantId)->exists();
    }
}
