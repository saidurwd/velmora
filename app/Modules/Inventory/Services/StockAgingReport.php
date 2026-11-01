<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How long stock has been sitting. For each product in each sellable place that holds units: the days since the last one left in a parcel, or, if none ever
 * has, since the stock first arrived. Counted in units (the system holds no cost price).
 */
final class StockAgingReport
{
    public const BUCKETS = ['0-30', '31-60', '61-90', '91+'];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{buckets: array<string, array{lines: int, units: int}>, oldest: list<array{variant_id: int, location: string, units: int, days: int}>}
     */
    public function report(int $oldest = 20): array
    {
        $now = $this->clock->now();
        // serves: one pass over the levels with units, with their last sale and first arrival from the ledger (index variant, location, id)
        $rows = DB::table('stock_levels as l')->join('stock_locations as p', 'p.id', '=', 'l.location_id')
            ->where('p.is_active', true)->where('p.type', '!=', 'quarantine')->where('l.on_hand', '>', 0)
            ->selectRaw("l.variant_id, p.code, l.on_hand, COALESCE((SELECT MAX(m.created_at) FROM stock_movements m WHERE m.variant_id = l.variant_id AND m.location_id = l.location_id AND m.reason = 'shipment'), (SELECT MIN(m2.created_at) FROM stock_movements m2 WHERE m2.variant_id = l.variant_id AND m2.location_id = l.location_id)) AS since")
            ->get();

        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[$bucket] = ['lines' => 0, 'units' => 0];
        }
        $aged = [];
        foreach ($rows as $row) {
            $days = $row->since === null ? 0 : (int) floor(CarbonImmutable::parse((string) $row->since, 'UTC')->diffInSeconds($now, false) / 86400);
            $bucket = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '91+',
            };
            $buckets[$bucket] = ['lines' => $buckets[$bucket]['lines'] + 1, 'units' => $buckets[$bucket]['units'] + (int) $row->on_hand];
            $aged[] = ['variant_id' => (int) $row->variant_id, 'location' => (string) $row->code, 'units' => (int) $row->on_hand, 'days' => $days];
        }
        usort($aged, fn (array $a, array $b): int => $b['days'] <=> $a['days'] ?: $a['variant_id'] <=> $b['variant_id']);

        return ['buckets' => $buckets, 'oldest' => array_slice($aged, 0, $oldest)];
    }
}
