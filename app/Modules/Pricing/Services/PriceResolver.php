<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\DTOs\PriceQuote;
use App\Modules\Pricing\Exceptions\PricingException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class PriceResolver implements PriceCatalog
{
    public function __construct(private readonly Clock $clock, private readonly TaxRates $taxRates, private readonly Settings $settings) {}

    public function quote(int $variantId, string $taxClassCode, ?CarbonInterface $at = null, string $listCode = 'retail'): PriceQuote
    {
        $at = ($at === null ? $this->clock->now() : CarbonImmutable::instance($at))->utc();
        $list = DB::table('price_lists')->where('code', $listCode)->first() ?? throw PricingException::unknownList($listCode);

        $regular = DB::table('prices')->where('variant_id', $variantId)->where('price_list_id', $list->id)->first()
            ?? throw PricingException::noPrice($variantId);

        // sale window is [starts_at, ends_at): the instant a sale ends, the regular price is back
        $sale = DB::table('price_sales')->where('variant_id', $variantId)->where('price_list_id', $list->id)
            ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.u'))->where('ends_at', '>', $at->format('Y-m-d H:i:s.u'))->first();

        $currency = (string) $list->currency;
        $regularMoney = Money::of((int) $regular->amount_minor, $currency);
        $price = $sale === null ? $regularMoney : Money::of((int) $sale->amount_minor, $currency);

        return new PriceQuote(
            $variantId,
            $listCode,
            $price,
            $regularMoney,
            // while on sale the regular price is the "was" price; otherwise the configured compare-at (if any)
            $sale !== null ? $regularMoney : ($regular->compare_at_minor === null ? null : Money::of((int) $regular->compare_at_minor, $currency)),
            $sale !== null,
            $sale === null ? null : CarbonImmutable::parse((string) $sale->ends_at, 'UTC'),
            $this->settings->bool('tax.prices_include_tax', true),
            $this->taxRates->rateBp($taxClassCode, $at),
        );
    }

    public function allHaveRegularPrice(array $variantIds, string $listCode = 'retail'): bool
    {
        if ($variantIds === []) {
            return false;
        }

        $list = DB::table('price_lists')->where('code', $listCode)->value('id');

        return $list !== null && DB::table('prices')->where('price_list_id', $list)->whereIn('variant_id', $variantIds)->count() === count(array_unique($variantIds));
    }
}
