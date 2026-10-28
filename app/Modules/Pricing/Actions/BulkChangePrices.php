<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\DTOs\BulkPricePreview;
use App\Modules\Pricing\Exceptions\PricingException;
use Illuminate\Support\Facades\DB;

/** Two steps by design: preview shows every old → new price; apply re-verifies nothing moved and writes it all atomically. */
final class BulkChangePrices
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $variantIds
     * @param  int  $percentBp  +1000 = raise 10%, -2000 = cut 20%
     * @param  int  $roundToMinor  round result to the nearest multiple (100 = ৳1, 500 = ৳5); 1 = no rounding
     */
    public function preview(array $variantIds, int $percentBp, int $roundToMinor = 1, string $listCode = 'retail'): BulkPricePreview
    {
        $list = DB::table('price_lists')->where('code', $listCode)->first() ?? throw PricingException::unknownList($listCode);
        $current = DB::table('prices')->where('price_list_id', $list->id)->whereIn('variant_id', $variantIds)->orderBy('variant_id')->get();
        $round = max(1, $roundToMinor);
        $rows = [];

        foreach ($current as $price) {
            $old = (int) $price->amount_minor;
            $scaled = $old * (10000 + $percentBp);
            $new = intdiv($scaled + 5000, 10000);                          // percentage, half-up to the paisa
            $new = max(0, intdiv($new + intdiv($round, 2), $round) * $round); // then to the nearest ৳1 / ৳5 / ... if asked
            $warning = match (true) {
                $new === $old => 'unchanged',
                $price->compare_at_minor !== null && $new >= (int) $price->compare_at_minor => 'at or above the compare-at price',
                $new === 0 => 'free',
                default => null,
            };

            if ($new !== $old) {
                $rows[] = ['variant_id' => (int) $price->variant_id, 'old_minor' => $old, 'new_minor' => $new, 'delta_minor' => $new - $old, 'warning' => $warning];
            }
        }

        return new BulkPricePreview($rows, $listCode, $this->fingerprint($list->id, array_column($rows, 'variant_id')));
    }

    public function apply(Actor $actor, BulkPricePreview $preview): int
    {
        if ($preview->isEmpty()) {
            throw PricingException::emptyChange();
        }

        return Transactions::run(function () use ($actor, $preview): int {
            $list = DB::table('price_lists')->where('code', $preview->listCode)->first() ?? throw PricingException::unknownList($preview->listCode);

            // lock the rows, then make sure they still hold exactly what the preview showed
            $variantIds = array_column($preview->rows, 'variant_id');
            DB::table('prices')->where('price_list_id', $list->id)->whereIn('variant_id', $variantIds)->lockForUpdate()->get();

            if (! hash_equals($preview->fingerprint, $this->fingerprint($list->id, $variantIds))) {
                throw PricingException::stalePreview();
            }

            foreach ($preview->rows as $row) {
                DB::table('prices')->where('price_list_id', $list->id)->where('variant_id', $row['variant_id'])->update(['amount_minor' => $row['new_minor'], 'updated_at' => now('UTC')]);
                $this->audit->record('price.bulk_changed', 'variant', ['amount_minor' => $row['old_minor']], ['amount_minor' => $row['new_minor']], $actor, $row['variant_id']);
            }

            return count($preview->rows);
        });
    }

    /** @param  list<int>  $variantIds */
    private function fingerprint(int $listId, array $variantIds): string
    {
        $rows = DB::table('prices')->where('price_list_id', $listId)->whereIn('variant_id', $variantIds)->orderBy('variant_id')->get(['variant_id', 'amount_minor', 'compare_at_minor']);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
