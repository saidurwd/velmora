<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\DigitalOrderLines;
use App\Modules\Orders\DTOs\DigitalOrderLine;
use Illuminate\Support\Facades\DB;

final class DatabaseDigitalOrderLines implements DigitalOrderLines
{
    public function paidLinesFor(int $orderId): array
    {
        // an order that is not paid has nothing to deliver, whatever event asked; the primary key finds the order, the order_id index its lines
        $paid = DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->exists();
        if (! $paid) {
            return [];
        }

        $lines = [];
        foreach (DB::table('order_items')->where('order_id', $orderId)->where('product_type', 'digital')->orderBy('id')->get(['id', 'variant_id', 'quantity']) as $r) {
            $lines[] = new DigitalOrderLine((int) $r->id, (int) $r->variant_id, (int) $r->quantity);
        }

        return $lines;
    }
}
