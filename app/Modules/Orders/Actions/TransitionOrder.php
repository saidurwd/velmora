<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Moves one axis of an order (status, payment, fulfilment) along its allowed path, and writes the history entry in the same
 * transaction. The order row is locked first and the update is conditional on the state we read, so two staff members (or a
 * staff member and a payment callback) acting at once are applied one after the other, never both on the same starting state.
 */
final class TransitionOrder
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly StockReservations $stock) {}

    /** @throws OrderException */
    public function status(Actor $actor, int $orderId, OrderStatus $to, ?string $reason = null): Order
    {
        return $this->apply($actor, $orderId, 'status', $to->value, $reason, function (Order $o) use ($to, $reason): array {
            if (! $o->status->canMoveTo($to)) {
                throw new OrderException($o->status === $to ? 'already_in_state' : 'illegal_transition', ['from' => $o->status->value, 'to' => $to->value]);
            }

            if ($to === OrderStatus::Cancelled) {
                if ($o->fulfillment_status->hasShipped()) {
                    throw new OrderException('cancel_after_shipping');
                }
                if ($o->payment_status->holdsMoney() || $o->payment_status === PaymentStatus::Refunded) {
                    throw new OrderException('cancel_needs_refund');
                }
                if ($reason === null || trim($reason) === '') {
                    throw new OrderException('reason_required');
                }
            }
            if ($to === OrderStatus::Completed && ($o->fulfillment_status !== FulfillmentStatus::Fulfilled || $o->payment_status !== PaymentStatus::Paid)) {
                throw new OrderException('complete_needs_delivery_and_payment');
            }

            return $to === OrderStatus::Cancelled ? ['status' => $to->value, 'cancelled_at' => $this->clock->now(), 'cancel_reason' => mb_substr(trim((string) $reason), 0, 255)] : ['status' => $to->value];
        }, 'status', fn (Order $o) => $o->status->value);
    }

    /** @throws OrderException */
    public function payment(Actor $actor, int $orderId, PaymentStatus $to, ?string $reason = null): Order
    {
        return $this->apply($actor, $orderId, 'payment', $to->value, $reason, function (Order $o) use ($to): array {
            if (! $o->payment_status->canMoveTo($to)) {
                throw new OrderException($o->payment_status === $to ? 'already_in_state' : 'illegal_transition', ['from' => $o->payment_status->value, 'to' => $to->value]);
            }
            if ($o->status === OrderStatus::Cancelled && $to !== PaymentStatus::Refunded && $to !== PaymentStatus::PartiallyRefunded) {
                throw new OrderException('order_cancelled');
            }

            return ['payment_status' => $to->value];
        }, 'payment', fn (Order $o) => $o->payment_status->value);
    }

    /** @throws OrderException */
    public function fulfillment(Actor $actor, int $orderId, FulfillmentStatus $to, ?string $reason = null): Order
    {
        return $this->apply($actor, $orderId, 'fulfillment', $to->value, $reason, function (Order $o) use ($to): array {
            if (! $o->fulfillment_status->canMoveTo($to)) {
                throw new OrderException($o->fulfillment_status === $to ? 'already_in_state' : 'illegal_transition', ['from' => $o->fulfillment_status->value, 'to' => $to->value]);
            }
            if ($o->status === OrderStatus::Cancelled) {
                throw new OrderException('order_cancelled');
            }
            if (in_array($to, [FulfillmentStatus::PartiallyFulfilled, FulfillmentStatus::Fulfilled], true) && ! in_array($o->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new OrderException('confirm_before_shipping');
            }

            return ['fulfillment_status' => $to->value];
        }, 'fulfillment', fn (Order $o) => $o->fulfillment_status->value);
    }

    /**
     * @param  callable(Order): array<string, mixed>  $decide  validates and returns the columns to set
     * @param  callable(Order): string  $current
     */
    private function apply(Actor $actor, int $orderId, string $axis, string $to, ?string $reason, callable $decide, string $auditAxis, callable $current): Order
    {
        $order = Transactions::run(function () use ($actor, $orderId, $axis, $to, $reason, $decide, $current): Order {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $from = $current($order);
            $changes = $decide($order);
            $column = match ($axis) {
                'status' => 'status',
                'payment' => 'payment_status',
                default => 'fulfillment_status',
            };

            $affected = DB::table('orders')->where('id', $orderId)->where($column, $from)->update($changes + ['updated_at' => $this->clock->now()]);
            if ($affected !== 1) {
                throw new OrderException('changed_meanwhile'); // cannot happen under the row lock; a belt-and-braces check
            }

            DB::table('order_status_history')->insert([
                'order_id' => $orderId, 'axis' => $axis, 'from_state' => $from, 'to_state' => $to,
                'actor_type' => $actor->type->value, 'actor_id' => $actor->id, 'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255), 'created_at' => $this->clock->now(),
            ]);

            if ($axis === 'status' && $to === OrderStatus::Cancelled->value) {
                $this->stock->releaseForOrder($order->public_id); // in the same transaction: a cancelled order never keeps stock
            }

            return $order->refresh();
        });

        $this->audit->record("order.{$auditAxis}.changed", $order, ['state' => null], ['state' => $to, 'reason' => $reason], $actor);

        return $order;
    }
}
