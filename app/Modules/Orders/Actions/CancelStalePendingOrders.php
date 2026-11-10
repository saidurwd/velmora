<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderMessages;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Settings;

/**
 * An order nobody confirmed (a cash order the team could not reach the customer for, or an online order whose payment never began)
 * does not hold stock forever: after `orders.pending_cancel_days` (default 3; 0 = never) it is cancelled, the stock goes back, and the
 * customer is told. Orders that are paid, being paid, or flagged for review are left to a person.
 */
final class CancelStalePendingOrders
{
    public function __construct(private readonly TransitionOrder $transition, private readonly Settings $settings, private readonly Clock $clock, private readonly OrderMessages $messages) {}

    /** @return int how many orders were cancelled */
    public function handle(int $limit = 200): int
    {
        $days = $this->settings->int('orders.pending_cancel_days', 3);
        if ($days <= 0) {
            return 0;
        }

        $cancelled = 0;
        $stale = Order::query()->where('status', OrderStatus::Pending->value)->whereIn('payment_status', ['unpaid', 'failed'])->where('needs_review', false)
            ->where('placed_at', '<=', $this->clock->now()->subDays($days)->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->get();

        foreach ($stale as $order) {
            try {
                $this->transition->status(Actor::system('auto-cancel'), $order->id, OrderStatus::Cancelled, "Not confirmed within {$days} days", CancelReason::Other);
            } catch (OrderException) {
                continue; // it moved on while we were looking: leave it
            }
            $cancelled++;
            $this->tell($order);
        }

        return $cancelled;
    }

    private function tell(Order $order): void
    {
        $this->messages->tryToSend($order, 'order.cancelled_stale.sms', [], 'order.cancelled_stale.sms|'.$order->public_id);   // the cancellation stands; only the message could fail
    }
}
