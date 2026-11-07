<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\SalesFigures;
use App\Modules\Orders\DTOs\LineSalesRow;
use App\Modules\Orders\DTOs\OrderFact;
use App\Modules\Orders\DTOs\SalesRow;
use App\Modules\Orders\DTOs\TaxSalesRow;
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

    public function linesPlacedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index orders (placed_at) for the day's orders, then their lines through order_items (order_id).
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.placed_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('o.placed_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))->where('o.status', '<>', 'cancelled')
            ->groupBy('i.variant_id', 'o.channel', 'o.payment_method_code', 'o.locale', 'o.currency')
            ->orderBy('i.variant_id')->orderBy('o.channel')->orderBy('o.payment_method_code')->orderBy('o.locale')->orderBy('o.currency')
            ->selectRaw('i.variant_id, MAX(i.sku_snapshot) AS sku, o.channel, o.payment_method_code, o.locale, o.currency, COUNT(DISTINCT o.id) AS orders, SUM(i.quantity) AS units, SUM(i.returned_qty) AS returned, SUM(i.quantity * i.unit_price_minor) AS gross, SUM(i.discount_minor) AS discounts')
            ->get();

        return array_values($rows->map(fn ($r): LineSalesRow => new LineSalesRow(
            (int) $r->variant_id, (string) $r->sku, (string) $r->channel, (string) $r->payment_method_code, (string) $r->locale, (string) $r->currency,
            (int) $r->orders, (int) $r->units, (int) $r->returned, (int) $r->gross, (int) $r->discounts,
        ))->all());
    }

    public function taxPlacedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->utc()->format('Y-m-d H:i:s.u');
        $end = $to->utc()->format('Y-m-d H:i:s.u');
        $goods = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end)->where('o.status', '<>', 'cancelled')
            ->groupBy('i.tax_rate_bp', 'o.currency', 'o.tax_inclusive')->orderBy('i.tax_rate_bp')->orderBy('o.currency')->orderBy('o.tax_inclusive')
            ->selectRaw("'goods' AS kind, i.tax_rate_bp AS rate, o.currency, o.tax_inclusive AS inclusive, COUNT(*) AS line_count, SUM(i.line_total_minor - IF(o.tax_inclusive, i.tax_minor, 0)) AS taxable, SUM(i.tax_minor) AS tax")
            ->get();
        $extra = DB::table('order_tax_lines as t')->join('orders as o', 'o.id', '=', 't.order_id')
            ->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end)->where('o.status', '<>', 'cancelled')
            ->groupBy('t.kind', 't.rate_bp', 'o.currency', 'o.tax_inclusive')->orderBy('t.kind')->orderBy('t.rate_bp')->orderBy('o.currency')->orderBy('o.tax_inclusive')
            ->selectRaw('t.kind AS kind, t.rate_bp AS rate, o.currency, o.tax_inclusive AS inclusive, COUNT(*) AS line_count, SUM(t.taxable_minor) AS taxable, SUM(t.tax_minor) AS tax')
            ->get();

        return array_values($goods->concat($extra)->map(fn ($r): TaxSalesRow => new TaxSalesRow(
            (string) $r->kind, (int) $r->rate, (string) $r->currency, (bool) $r->inclusive, (int) $r->line_count, (int) $r->taxable, (int) $r->tax,
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
