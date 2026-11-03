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
        foreach (DB::table('order_items')->where('order_id', $orderId)->where('product_type', 'service')->orderBy('id')->get(['id', 'variant_id']) as $r) {
            $lines[] = new ServiceOrderLine((int) $r->id, (int) $r->variant_id);
        }

        return $lines;
    }
}
