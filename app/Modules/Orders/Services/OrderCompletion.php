<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Actor;

/**
 * An order is complete when every parcel was delivered AND the money is in. Delivery and payment arrive as separate announcements in either order, so each one
 * asks this question and the second to arrive finishes the job. Not ready is not an error.
 */
final class OrderCompletion
{
    public function __construct(private readonly TransitionOrder $transition) {}

    public function attempt(int $orderId): bool
    {
        $order = Order::query()->find($orderId);
        if ($order === null || $order->delivered_at === null || $order->status !== OrderStatus::Processing || $order->fulfillment_status !== FulfillmentStatus::Fulfilled || $order->payment_status !== PaymentStatus::Paid) {
            return false;
        }

        try {
            $this->transition->status(Actor::system('fulfilment'), $orderId, OrderStatus::Completed, 'Delivered and paid');
        } catch (OrderException) {
            return false; // changed meanwhile (cancelled, completed by someone else): nothing to do
        }

        return true;
    }
}
