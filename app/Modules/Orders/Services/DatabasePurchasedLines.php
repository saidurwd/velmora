<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Orders\Contracts\PurchasedLines;
use App\Modules\Orders\DTOs\PurchasedLine;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * A line can be reviewed when the customer really has what they bought: the order is completed (delivered and paid), or the line is a digital one and the order is paid.
 * A fully refunded or cancelled order never counts. Only the customer's own orders are ever looked at.
 */
final class DatabasePurchasedLines implements PurchasedLines
{
    public function __construct(private readonly PurchasableVariants $variants) {}

    public function find(int $userId, string $orderPublicId, string $variantPublicId): ?PurchasedLine
    {
        $variantId = $this->variants->idByPublicId($variantPublicId);
        if ($variantId === null) {
            return null;
        }
        foreach ($this->forOrder($userId, $orderPublicId) as $line) {
            if ($line->variantId === $variantId) {
                return $line;
            }
        }

        return null;
    }

    public function forOrder(int $userId, string $orderPublicId): array
    {
        $order = Order::query()->where('user_id', $userId)->where('public_id', $orderPublicId)->with('items')->first();   // unique public_id
        if ($order === null) {
            return [];
        }

        $status = $order->status->value;
        $paid = in_array($order->payment_status->value, ['paid', 'partially_refunded'], true);
        $placed = CarbonImmutable::parse($order->placed_at);
        $lines = [];
        foreach ($order->items as $item) {
            $reason = match (true) {
                $status === 'cancelled' || $order->payment_status->value === 'refunded' => 'refunded',
                $status === 'completed' && $paid => null,
                $item->product_type === 'digital' && $paid => null,
                default => 'not_complete',
            };
            $lines[] = new PurchasedLine((int) $item->id, (int) $item->variant_id, (string) $item->product_type, (string) $order->public_id, $placed, $reason === null, $reason);
        }

        return $lines;
    }
}
