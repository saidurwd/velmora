<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\DTOs\RateBand;
use App\Modules\Shipping\Exceptions\ShippingException;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a delivery zone: its name, the districts it covers, whether cash on delivery is offered, and the price bands
 * for each delivery method. Everything is saved together or not at all. A district can be in only one zone: choosing it here
 * takes it from the zone that had it. There is always at most one "everywhere else" (fallback) zone: making this one it moves the title.
 */
final class SaveShippingZone
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, string|null>  $names  locale => name
     * @param  list<int>  $districtIds
     * @param  array<int, list<RateBand>>  $rates  method id => bands (a method not listed keeps its bands; an empty list removes them)
     *
     * @throws ShippingException
     */
    public function handle(Actor $actor, ?ShippingZone $zone, array $names, bool $isFallback, bool $codAvailable, bool $isActive, int $sortOrder, array $districtIds, array $rates): ShippingZone
    {
        if ($isFallback && ! $isActive) {
            throw new ShippingException('fallback_must_be_active');
        }
        foreach ($rates as $methodId => $bands) {
            $this->assertBands($methodId, $bands);
        }

        $isNew = $zone === null;
        $saved = Transactions::run(function () use ($zone, $names, $isFallback, $codAvailable, $isActive, $sortOrder, $districtIds, $rates): ShippingZone {
            if ($isFallback) {
                DB::table('shipping_zones')->when($zone !== null, fn ($q) => $q->where('id', '!=', $zone?->id))->update(['is_fallback' => false]);
            }

            $zone ??= new ShippingZone;
            $zone->forceFill(['is_fallback' => $isFallback, 'cod_available' => $codAvailable, 'is_active' => $isActive, 'sort_order' => $sortOrder])->save();

            foreach ($names as $locale => $name) {
                $name = trim(BanglaText::input((string) $name));
                if ($name === '') {
                    DB::table('shipping_zone_translations')->where('zone_id', $zone->id)->where('locale', $locale)->delete();

                    continue;
                }
                DB::table('shipping_zone_translations')->upsert([['zone_id' => $zone->id, 'locale' => $locale, 'name' => mb_substr($name, 0, 120)]], ['zone_id', 'locale'], ['name']);
            }

            $districtIds = array_values(array_unique($districtIds));
            DB::table('shipping_zone_districts')->where('zone_id', '!=', $zone->id)->whereIn('district_id', $districtIds)->delete(); // taken from the zone that had it
            DB::table('shipping_zone_districts')->where('zone_id', $zone->id)->when($districtIds !== [], fn ($q) => $q->whereNotIn('district_id', $districtIds))->delete();
            $have = DB::table('shipping_zone_districts')->where('zone_id', $zone->id)->pluck('district_id')->map(fn ($d) => (int) $d)->all();
            foreach (array_diff($districtIds, $have) as $districtId) {
                DB::table('shipping_zone_districts')->insert(['zone_id' => $zone->id, 'district_id' => $districtId]);
            }

            $now = now('UTC');
            foreach ($rates as $methodId => $bands) {
                DB::table('shipping_rates')->where('zone_id', $zone->id)->where('method_id', $methodId)->delete();
                foreach ($bands as $band) {
                    DB::table('shipping_rates')->insert(['zone_id' => $zone->id, 'method_id' => $methodId, 'max_weight_g' => $band->maxWeightG, 'price_minor' => $band->priceMinor, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            return $zone->refresh();
        });

        $this->audit->record($isNew ? 'shipping.zone.created' : 'shipping.zone.updated', $saved, [], ['is_fallback' => $isFallback, 'cod_available' => $codAvailable, 'is_active' => $isActive, 'districts' => count($districtIds), 'methods_priced' => array_keys($rates)], $actor);

        return $saved;
    }

    /** @param  list<RateBand>  $bands */
    private function assertBands(int $methodId, array $bands): void
    {
        $weights = array_map(fn (RateBand $b) => $b->maxWeightG, $bands);
        $limited = array_filter($weights, fn ($w) => $w !== null);

        if (count($weights) - count($limited) > 1) {
            throw new ShippingException('rate_two_open_bands');
        }
        if (count($limited) !== count(array_unique($limited))) {
            throw new ShippingException('rate_duplicate_weight');
        }
        foreach ($bands as $b) {
            if ($b->priceMinor < 0 || ($b->maxWeightG !== null && $b->maxWeightG < 1)) {
                throw new ShippingException('rate_invalid');
            }
        }
    }
}
