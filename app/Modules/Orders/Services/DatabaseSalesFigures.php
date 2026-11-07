<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\SalesFigures;
use App\Modules\Orders\DTOs\OrderFact;
use App\Modules\Orders\DTOs\SalesRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseSalesFigures implements SalesFigures
{
    public function placedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index orders (placed_at): one day of orders, then a small group by.
        $rows = DB::table('orders')
            ->where('placed_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('placed_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->groupBy('channel', 'payment_method_code', 'locale', 'currency')
            ->orderBy('channel')->orderBy('payment_method_code')->orderBy('locale')->orderBy('currency')
            ->selectRaw(
                'channel, payment_method_code, locale, currency, '
                ."COALESCE(SUM(status <> 'cancelled'), 0) AS orders, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN subtotal_minor ELSE 0 END), 0) AS gross, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN discount_minor ELSE 0 END), 0) AS discounts, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN shipping_minor ELSE 0 END), 0) AS shipping, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN cod_fee_minor ELSE 0 END), 0) AS cod_fee, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN tax_minor ELSE 0 END), 0) AS tax, "
                ."COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN grand_total_minor ELSE 0 END), 0) AS billed, "
                ."COALESCE(SUM(status = 'cancelled'), 0) AS cancelled_orders, "
                ."COALESCE(SUM(CASE WHEN status = 'cancelled' THEN grand_total_minor ELSE 0 END), 0) AS cancelled_billed"
            )
            ->get();

        return array_values($rows->map(fn ($r): SalesRow => new SalesRow(
            (string) $r->channel, (string) $r->payment_method_code, (string) $r->locale, (string) $r->currency, (int) $r->orders, (int) $r->gross, (int) $r->discounts,
            (int) $r->shipping, (int) $r->cod_fee, (int) $r->tax, (int) $r->billed, (int) $r->cancelled_orders, (int) $r->cancelled_billed,
        ))->all());
    }

    public function facts(array $orderIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($orderIds)), 1000) as $chunk) {
            foreach (DB::table('orders')->whereIn('id', $chunk)->get(['id', 'channel', 'locale', 'status']) as $r) {   // primary key
                $out[(int) $r->id] = new OrderFact((string) $r->channel, (string) $r->locale, $r->status === 'cancelled');
            }
        }

        return $out;
    }
}
