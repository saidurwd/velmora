<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\StockReportFigures;
use App\Modules\Inventory\DTOs\AgedStockLine;
use App\Modules\Inventory\DTOs\StockLine;
use Illuminate\Support\Facades\DB;

final class DatabaseStockReportFigures implements StockReportFigures
{
    public function __construct(private readonly StockAgingReport $aging) {}

    public function needingAttention(int $limit): array
    {
        // serves: levels in sellable places with nothing to sell or at/below their low level, least available first (a scan of the levels of tracked stock)
        $rows = DB::table('stock_levels as l')->join('stock_locations as p', 'p.id', '=', 'l.location_id')
            ->where('p.is_active', true)->where('p.type', '!=', 'quarantine')
            ->whereRaw('((l.on_hand - l.reserved) <= 0 OR (l.low_stock_threshold IS NOT NULL AND (l.on_hand - l.reserved) <= l.low_stock_threshold))')
            ->orderByRaw('(l.on_hand - l.reserved)')->orderBy('l.variant_id')->orderBy('p.code')->limit($limit)
            ->get(['l.variant_id', 'p.code', 'l.on_hand', 'l.reserved', 'l.low_stock_threshold']);

        return array_values($rows->map(fn ($r): StockLine => new StockLine(
            (int) $r->variant_id, (string) $r->code, (int) $r->on_hand, (int) $r->reserved, (int) $r->on_hand - (int) $r->reserved, $r->low_stock_threshold === null ? null : (int) $r->low_stock_threshold,
        ))->all());
    }

    public function oldest(int $limit): array
    {
        return array_map(
            fn (array $r): AgedStockLine => new AgedStockLine($r['variant_id'], $r['location'], $r['units'], $r['days']),
            $this->aging->report($limit)['oldest'],
        );
    }
}
