<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Read side of the stock screens (staff only). */
final class StockAdminReader
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, array{on_hand: int, reserved: int, available: int}> totals across active locations, keyed by variant id
     */
    public function totals(array $variantIds): array
    {
        $rows = DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.is_active', true)->whereIn('stock_levels.variant_id', $variantIds)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id, SUM(stock_levels.on_hand) AS on_hand, SUM(stock_levels.reserved) AS reserved')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->variant_id] = ['on_hand' => (int) $r->on_hand, 'reserved' => (int) $r->reserved, 'available' => (int) $r->on_hand - (int) $r->reserved];
        }

        return $out;
    }

    /** @return list<array{code: string, name: string, on_hand: int, reserved: int, available: int}> every active location, zero where no row exists */
    public function byLocation(int $variantId): array
    {
        return array_values(DB::table('stock_locations')
            ->leftJoin('stock_levels', fn ($j) => $j->on('stock_levels.location_id', '=', 'stock_locations.id')->where('stock_levels.variant_id', $variantId))
            ->where('stock_locations.is_active', true)->orderBy('stock_locations.id')
            ->get(['stock_locations.code', 'stock_locations.name', 'stock_levels.on_hand', 'stock_levels.reserved'])
            ->map(fn ($r) => ['code' => (string) $r->code, 'name' => (string) $r->name, 'on_hand' => (int) $r->on_hand, 'reserved' => (int) $r->reserved, 'available' => (int) $r->on_hand - (int) $r->reserved])
            ->all());
    }

    /** @return list<string> */
    public function activeLocationCodes(): array
    {
        return array_values(array_map('strval', DB::table('stock_locations')->where('is_active', true)->orderBy('id')->pluck('code')->all()));
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function movements(int $variantId, int $perPage = 50): LengthAwarePaginator
    {
        return DB::table('stock_movements')->join('stock_locations', 'stock_locations.id', '=', 'stock_movements.location_id')
            ->where('stock_movements.variant_id', $variantId)->orderByDesc('stock_movements.id')
            ->select('stock_movements.id', 'stock_movements.delta', 'stock_movements.reason', 'stock_movements.note', 'stock_movements.ref_type', 'stock_movements.ref_id', 'stock_movements.created_at', 'stock_locations.code as location')
            ->paginate($perPage);
    }
}
