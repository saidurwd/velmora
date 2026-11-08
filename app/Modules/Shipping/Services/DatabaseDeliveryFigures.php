<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Contracts\DeliveryFigures;
use App\Modules\Shipping\DTOs\CourierDeliveryRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseDeliveryFigures implements DeliveryFigures
{
    public function handedOverBetween(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        // Index shipments (handed_over_at, courier): the period's parcels, then a small group by.
        $rows = DB::table('shipments')
            ->where('handed_over_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('handed_over_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->whereNotIn('status', ['created', 'cancelled'])
            ->groupBy('courier')->orderBy('courier')
            ->selectRaw(
                'courier, COUNT(*) AS parcels, '
                ."COALESCE(SUM(status = 'delivered'), 0) AS delivered, "
                ."COALESCE(SUM(status = 'failed'), 0) AS failed, "
                ."COALESCE(SUM(status = 'returned'), 0) AS returned, "
                ."COALESCE(SUM(status IN ('handed_over','in_transit','out_for_delivery','unknown')), 0) AS in_transit, "
                .'COALESCE(SUM(attempts), 0) AS attempts, '
                ."COALESCE(SUM(CASE WHEN status = 'delivered' THEN TIMESTAMPDIFF(SECOND, handed_over_at, delivered_at) ELSE 0 END), 0) AS delivery_seconds, "
                .'COALESCE(SUM(CASE WHEN currency = ? THEN cod_amount_minor ELSE 0 END), 0) AS cod',
            )->addBinding($currency, 'select')
            ->get();

        return array_values($rows->map(fn ($r): CourierDeliveryRow => new CourierDeliveryRow(
            (string) $r->courier, (int) $r->parcels, (int) $r->delivered, (int) $r->failed, (int) $r->returned, (int) $r->in_transit, (int) $r->attempts, (int) $r->delivery_seconds, (int) $r->cod,
        ))->all());
    }
}
