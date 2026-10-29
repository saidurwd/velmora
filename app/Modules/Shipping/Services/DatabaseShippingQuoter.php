<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuote;
use App\Modules\Shipping\DTOs\ShippingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zone from the delivery district (or the fallback zone), then for every active method the cheapest-fitting weight band.
 * Free delivery when the order value reaches the method's threshold or a free-shipping coupon applies. Cash on delivery
 * needs both the zone and the method to allow it. Three queries however many methods exist.
 */
final class DatabaseShippingQuoter implements ShippingQuoter
{
    public function quote(ShippingRequest $request, string $locale): ShippingQuote
    {
        if (! $request->requiresShipping) {
            return new ShippingQuote(false, []);
        }

        $default = (string) config('app.default_locale');

        $zone = $request->districtId === null ? null : DB::table('shipping_zone_districts as zd')->join('shipping_zones as z', 'z.id', '=', 'zd.zone_id')
            ->where('zd.district_id', $request->districtId)->where('z.is_active', true)->first(['z.id', 'z.cod_available']);
        $zone ??= DB::table('shipping_zones')->where('is_fallback', true)->where('is_active', true)->first(['id', 'cod_available']);
        if ($zone === null) {
            return new ShippingQuote(true, []);
        }

        $zoneName = DB::table('shipping_zone_translations')->where('zone_id', $zone->id)->whereIn('locale', [$locale, $default])->orderByRaw('locale = ? DESC', [$locale])->value('name');

        $methods = DB::table('shipping_methods as m')
            ->leftJoin('shipping_method_translations as tl', fn ($j) => $j->on('tl.method_id', '=', 'm.id')->where('tl.locale', $locale))
            ->leftJoin('shipping_method_translations as td', fn ($j) => $j->on('td.method_id', '=', 'm.id')->where('td.locale', $default))
            ->where('m.is_active', true)->orderBy('m.sort_order')->orderBy('m.id')
            ->get(['m.id', 'm.code', 'm.cod_available', 'm.cod_fee_minor', 'm.free_over_minor', DB::raw('COALESCE(tl.name, td.name, m.code) as name'), DB::raw('COALESCE(tl.delivery_estimate, td.delivery_estimate) as estimate')]);

        $bands = DB::table('shipping_rates')->where('zone_id', $zone->id)->whereIn('method_id', $methods->pluck('id'))->get(['method_id', 'max_weight_g', 'price_minor'])->groupBy('method_id');

        $options = [];
        foreach ($methods as $m) {
            $price = $this->bandPrice($bands->get($m->id), $request->totalWeightG);
            if ($price === null) {
                continue; // heavier than this method carries, or no rates set up for this zone
            }

            $free = $request->freeShippingCoupon || ($m->free_over_minor !== null && $request->merchandiseMinor >= (int) $m->free_over_minor);
            $cod = (bool) $zone->cod_available && (bool) $m->cod_available;

            $options[] = new ShippingOption(
                (string) $m->code, (string) $m->name, $m->estimate === null ? null : (string) $m->estimate,
                $free ? Money::zero() : Money::of($price), $free, $cod, $cod ? Money::of((int) $m->cod_fee_minor) : Money::zero(),
            );
        }

        return new ShippingQuote(true, $options, $zoneName === null ? null : (string) $zoneName);
    }

    /** @param  Collection<int, \stdClass>|null  $bands */
    private function bandPrice($bands, int $weightG): ?int
    {
        if ($bands === null) {
            return null;
        }

        $fitting = $bands->filter(fn ($b) => $b->max_weight_g === null || $weightG <= (int) $b->max_weight_g);
        if ($fitting->isEmpty()) {
            return null;
        }

        // the smallest band that still fits (an open "and above" band counts as largest)
        $band = $fitting->sortBy(fn ($b) => $b->max_weight_g === null ? PHP_INT_MAX : (int) $b->max_weight_g)->first();

        return $band === null ? null : (int) $band->price_minor;
    }
}
