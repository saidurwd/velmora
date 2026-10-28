<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Pricing\Exceptions\PricingException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ScheduleSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $startsLocal  e.g. "2026-12-01 00:00" in Asia/Dhaka (what staff type); stored in UTC
     * @param  string  $endsLocal  exclusive end, Asia/Dhaka
     */
    public function handle(Actor $actor, int $variantId, Money $salePrice, string $startsLocal, string $endsLocal, string $listCode = 'retail'): int
    {
        $zone = (string) config('app.display_timezone');
        $starts = CarbonImmutable::parse($startsLocal, $zone)->utc();
        $ends = CarbonImmutable::parse($endsLocal, $zone)->utc();

        if ($ends->lessThanOrEqualTo($starts)) {
            throw PricingException::badWindow();
        }

        $list = DB::table('price_lists')->where('code', $listCode)->first() ?? throw PricingException::unknownList($listCode);
        $regular = DB::table('prices')->where('variant_id', $variantId)->where('price_list_id', $list->id)->first() ?? throw PricingException::noPrice($variantId);

        if ($salePrice->currency !== $list->currency) {
            throw PricingException::currencyMismatch((string) $list->currency, $salePrice->currency);
        }

        if ($salePrice->amountMinor >= (int) $regular->amount_minor) {
            throw PricingException::compareAtTooLow(); // a "sale" must actually be cheaper
        }

        try {
            $id = (int) DB::table('price_sales')->insertGetId([
                'variant_id' => $variantId, 'price_list_id' => $list->id, 'amount_minor' => $salePrice->amountMinor, 'currency' => $salePrice->currency,
                'starts_at' => $starts->format('Y-m-d H:i:s.u'), 'ends_at' => $ends->format('Y-m-d H:i:s.u'), 'created_by' => $actor->id,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw PricingException::saleOverlaps();
        }

        $this->audit->record('price.sale_scheduled', 'variant', after: ['sale_minor' => $salePrice->amountMinor, 'starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String()], actor: $actor, subjectId: $variantId);

        return $id;
    }

    public function cancel(Actor $actor, int $saleId): void
    {
        $sale = DB::table('price_sales')->where('id', $saleId)->first();

        if ($sale !== null) {
            DB::table('price_sales')->where('id', $saleId)->delete();
            $this->audit->record('price.sale_cancelled', 'variant', ['sale_minor' => $sale->amount_minor, 'starts_at' => $sale->starts_at, 'ends_at' => $sale->ends_at], actor: $actor, subjectId: (int) $sale->variant_id);
        }
    }
}
