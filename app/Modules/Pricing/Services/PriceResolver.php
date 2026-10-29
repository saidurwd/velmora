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

    public function quoteMany(array $taxClassByVariant, ?CarbonInterface $at = null, string $listCode = 'retail'): array
    {
        if ($taxClassByVariant === []) {
            return [];
        }

        $at = ($at === null ? $this->clock->now() : CarbonImmutable::instance($at))->utc();
        $when = $at->format('Y-m-d H:i:s.u');
        $ids = array_keys($taxClassByVariant);
        $list = DB::table('price_lists')->where('code', $listCode)->first() ?? throw PricingException::unknownList($listCode);

        $regular = DB::table('prices')->where('price_list_id', $list->id)->whereIn('variant_id', $ids)->get()->keyBy('variant_id');
        $sales = DB::table('price_sales')->where('price_list_id', $list->id)->whereIn('variant_id', $ids)->where('starts_at', '<=', $when)->where('ends_at', '>', $when)->get()->keyBy('variant_id');
        $rates = [];
        foreach (array_unique($taxClassByVariant) as $code) {
            $rates[$code] = $this->taxRates->rateBp($code, $at);
        }
        $inclusive = $this->settings->bool('tax.prices_include_tax', true);
        $currency = (string) $list->currency;

        $out = [];
        foreach ($taxClassByVariant as $variantId => $taxClass) {
            $r = $regular->get($variantId);
            if ($r === null) {
                continue;
            }
            $s = $sales->get($variantId);
            $regularMoney = Money::of((int) $r->amount_minor, $currency);
            $out[(int) $variantId] = new PriceQuote(
                (int) $variantId, $listCode, $s === null ? $regularMoney : Money::of((int) $s->amount_minor, $currency), $regularMoney,
                $s !== null ? $regularMoney : ($r->compare_at_minor === null ? null : Money::of((int) $r->compare_at_minor, $currency)),
                $s !== null, $s === null ? null : CarbonImmutable::parse((string) $s->ends_at, 'UTC'), $inclusive, $rates[$taxClass],
            );
        }

        return $out;
    }

    public function changedVariantIds(CarbonInterface $since, CarbonInterface $until): array
    {
        $from = $since->utc()->format('Y-m-d H:i:s.u');
        $to = $until->utc()->format('Y-m-d H:i:s.u');

        $edited = DB::table('prices')->where('updated_at', '>', $from)->where('updated_at', '<=', $to)->pluck('variant_id');
        $started = DB::table('price_sales')->where('starts_at', '>', $from)->where('starts_at', '<=', $to)->pluck('variant_id');
        $ended = DB::table('price_sales')->where('ends_at', '>', $from)->where('ends_at', '<=', $to)->pluck('variant_id');
        $touched = DB::table('price_sales')->where('updated_at', '>', $from)->where('updated_at', '<=', $to)->pluck('variant_id'); // sale created or edited

        return array_values(array_unique(array_map('intval', [...$edited->all(), ...$started->all(), ...$ended->all(), ...$touched->all()])));
    }

    public function regularPrices(array $variantIds, string $listCode = 'retail'): array
    {
        $out = [];
        $rows = DB::table('prices')->join('price_lists', 'price_lists.id', '=', 'prices.price_list_id')
            ->where('price_lists.code', $listCode)->whereIn('prices.variant_id', $variantIds)->get(['prices.variant_id', 'prices.amount_minor', 'prices.compare_at_minor']);

        foreach ($rows as $r) {
            $out[(int) $r->variant_id] = ['amount_minor' => (int) $r->amount_minor, 'compare_at_minor' => $r->compare_at_minor === null ? null : (int) $r->compare_at_minor];
        }

        return $out;
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
