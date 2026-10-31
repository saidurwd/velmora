<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentInitiated;
use App\Modules\Payments\Events\PaymentNeedsReview;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\DomainEvent;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings an order in line with what Payments reports. This is the ONLY way an online payment changes an order: Payments announces
 * (through the outbox) and the order follows, so the two modules never reach into each other. Each announcement is applied at most
 * once, and an already-applied or no-longer-applicable one is a quiet no-op, never an error loop.
 */
final class ApplyPaymentEvent
{
    public function __construct(private readonly TransitionOrder $transition, private readonly IdempotentConsumer $consumer, private readonly Clock $clock) {}

    public function initiated(PaymentInitiated $e): void
    {
        $this->once('orders.payment_initiated', $e, fn () => $this->payment($e->orderId, PaymentStatus::Pending, 'payment started'));
    }

    public function succeeded(PaymentSucceeded $e): void
    {
        $this->once('orders.payment_succeeded', $e, function () use ($e): void {
            $order = Order::query()->find($e->orderId);
            if ($order === null) {
                return;
            }
            if ($order->payment_status === PaymentStatus::Failed) {
                $this->payment($e->orderId, PaymentStatus::Pending, 'payment retried'); // failed cannot go straight to paid
            }
            $this->payment($e->orderId, PaymentStatus::Paid, 'paid online');

            // paid in full online: nobody needs to phone the customer, unless the order is being looked at for another reason
            $order->refresh();
            if ($order->status === OrderStatus::Pending && ! $order->needs_review) {
                $this->status($e->orderId, OrderStatus::Confirmed, 'paid online');
            }
        });
    }

    public function failed(PaymentFailed $e): void
    {
        $this->once('orders.payment_failed', $e, fn () => $this->payment($e->orderId, PaymentStatus::Failed, $e->detail === null ? 'payment failed' : 'payment '.$e->detail));
    }

    public function expired(PaymentExpired $e): void
    {
        $this->once('orders.payment_expired', $e, fn () => $this->status($e->orderId, OrderStatus::Cancelled, 'Payment not completed in time'));
    }

    public function needsReview(PaymentNeedsReview $e): void
    {
        $this->once('orders.payment_needs_review', $e, function () use ($e): void {
            $now = $this->clock->now();
            $held = DB::table('orders')->where('id', $e->orderId)->where('status', '!=', OrderStatus::Cancelled->value)->update(['needs_review' => true, 'updated_at' => $now]);
            DB::table('order_notes')->insert(['order_id' => $e->orderId, 'author_id' => null, 'visibility' => 'internal', 'body' => __('orders::notifications.payment_needs_review_note', ['reason' => $e->detail ?? '—']), 'created_at' => $now]);
            if ($held === 0) {
                Log::warning('orders.payment_needs_review_on_closed_order', ['order_id' => $e->orderId, 'payment' => $e->paymentPublicId]);
            }
        });
    }

    private function payment(int $orderId, PaymentStatus $to, string $reason): void
    {
        $this->apply(fn () => $this->transition->payment(Actor::system('payments'), $orderId, $to, $reason), $orderId);
    }

    private function status(int $orderId, OrderStatus $to, string $reason): void
    {
        $this->apply(fn () => $this->transition->status(Actor::system('payments'), $orderId, $to, $reason), $orderId);
    }

    /** @param  callable(): mixed  $move */
    private function apply(callable $move, int $orderId): void
    {
        try {
            $move();
        } catch (OrderException $e) {
            if ($e->reason === 'already_in_state') {
                return; // someone (or an earlier delivery) already did this
            }
            // the order cannot follow (for example it was cancelled meanwhile). Retrying cannot help, so say so loudly instead of looping.
            Log::error('orders.payment_event_not_applied', ['order_id' => $orderId, 'reason' => $e->reason, 'params' => $e->params]);
        }
    }

    /** @param  callable(): void  $work */
    private function once(string $consumer, DomainEvent $event, callable $work): void
    {
        $this->consumer->once($consumer, $event, $work(...));
    }
}
