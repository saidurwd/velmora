<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/** Products at or below the level someone set as "low", per place, least left first. Stock in quarantine is not for sale and is not counted. */
final class LowStockReport
{
    /** @return list<array{variant_id: int, location: string, on_hand: int, reserved: int, available: int, threshold: int}> */
    public function rows(?string $locationCode = null, int $limit = 200): array
    {
        // serves: levels with a threshold, in sellable places, least available first (a scan of the levels that have a threshold)
        $rows = DB::table('stock_levels as l')->join('stock_locations as p', 'p.id', '=', 'l.location_id')
            ->where('p.is_active', true)->where('p.type', '!=', 'quarantine')->whereNotNull('l.low_stock_threshold')->whereRaw('(l.on_hand - l.reserved) <= l.low_stock_threshold')
            ->when($locationCode !== null, fn ($q) => $q->where('p.code', $locationCode))
            ->orderByRaw('(l.on_hand - l.reserved)')->orderBy('l.variant_id')->limit($limit)
            ->get(['l.variant_id', 'p.code', 'l.on_hand', 'l.reserved', 'l.low_stock_threshold'])
            ->map(fn ($r): array => ['variant_id' => (int) $r->variant_id, 'location' => (string) $r->code, 'on_hand' => (int) $r->on_hand, 'reserved' => (int) $r->reserved, 'available' => (int) $r->on_hand - (int) $r->reserved, 'threshold' => (int) $r->low_stock_threshold])
            ->all();

        return array_values($rows);
    }
}
