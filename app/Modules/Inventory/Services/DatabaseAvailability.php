<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\Availability;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DatabaseAvailability implements Availability
{
    public function forVariant(int $variantId): int
    {
        return $this->forVariants([$variantId])[$variantId] ?? 0;
    }

    public function changedVariantIds(CarbonInterface $since, CarbonInterface $until): array
    {
        return array_values(array_map('intval', DB::table('stock_levels')->where('updated_at', '>', $since->utc()->format('Y-m-d H:i:s.u'))->where('updated_at', '<=', $until->utc()->format('Y-m-d H:i:s.u'))->distinct()->pluck('variant_id')->all()));
    }

    public function forVariants(array $variantIds): array
    {
        $out = array_fill_keys($variantIds, 0);

        if ($variantIds === []) {
            return $out;
        }

        $rows = DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.is_active', true)->whereIn('stock_levels.variant_id', $variantIds)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id as variant_id, SUM(stock_levels.on_hand - stock_levels.reserved) as available')
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->variant_id] = max(0, (int) $row->available);
        }

        return $out;
    }
}
