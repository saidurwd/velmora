<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * When a customer may ASK for a cancellation: the order is still open, nothing has left the shop, the customer cannot simply cancel it themselves (money was taken, or
 * a payment is in progress, or it is already confirmed), and it holds something that is posted (digital goods and services have their own refund and cancellation rules).
 */
final class CancellationRequestRules
{
    /** The reason this order cannot have a cancellation request, or null when it can. */
    public static function deniedReason(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Cancelled => 'already_cancelled',
            $order->status === OrderStatus::Completed => 'order_complete',
            $order->fulfillment_status !== FulfillmentStatus::Unfulfilled => 'already_shipped',
            CancelOwnOrder::deniedReason($order) === null => 'cancel_yourself',
            ! $order->items->contains(fn ($item) => $item->product_type === 'physical') => 'no_physical_line',
            default => null,
        };
    }
}
