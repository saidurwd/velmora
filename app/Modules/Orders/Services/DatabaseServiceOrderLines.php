<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Orders\DTOs\ServiceOrderLine;
use Illuminate\Support\Facades\DB;

final class DatabaseServiceOrderLines implements ServiceOrderLines
{
    public function paidLinesFor(int $orderId): array
    {
        // an order that is not paid confirms nothing, whatever event asked; the primary key finds the order, the order_id index its lines
        return DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->exists() ? $this->linesFor($orderId) : [];
    }

    public function linesFor(int $orderId): array
    {
        $lines = [];
        foreach (DB::table('order_items')->where('order_id', $orderId)->where('product_type', 'service')->orderBy('id')->get(['id', 'variant_id', 'order_id', 'line_total_minor']) as $r) {
            $lines[] = new ServiceOrderLine((int) $r->id, (int) $r->variant_id, (int) $r->order_id, (int) $r->line_total_minor);
        }

        return $lines;
    }

    public function linesForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $lines = [];
        foreach (DB::table('order_items')->whereIn('order_id', $orderIds)->where('product_type', 'service')->orderBy('id')->get(['id', 'variant_id', 'order_id', 'line_total_minor']) as $r) {   // index order_id
            $lines[] = new ServiceOrderLine((int) $r->id, (int) $r->variant_id, (int) $r->order_id, (int) $r->line_total_minor);
        }

        return $lines;
    }

    public function linesForItems(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        $lines = [];
        foreach (DB::table('order_items')->whereIn('id', $orderItemIds)->where('product_type', 'service')->orderBy('id')->get(['id', 'variant_id', 'order_id', 'line_total_minor']) as $r) {   // primary key
            $lines[] = new ServiceOrderLine((int) $r->id, (int) $r->variant_id, (int) $r->order_id, (int) $r->line_total_minor);
        }

        return $lines;
    }

    public function lineFor(int $orderItemId): ?ServiceOrderLine
    {
        $r = DB::table('order_items')->where('id', $orderItemId)->where('product_type', 'service')->first(['id', 'variant_id', 'order_id', 'line_total_minor']);   // primary key

        return $r === null ? null : new ServiceOrderLine((int) $r->id, (int) $r->variant_id, (int) $r->order_id, (int) $r->line_total_minor);
    }
}
