<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCompletion;
use App\Modules\Orders\Services\OrderMessages;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\DomainEvent;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Shipping\Events\ShipmentDelivered;
use App\Modules\Shipping\Events\ShipmentFailed;
use App\Modules\Shipping\Events\ShipmentHandedOver;
use App\Modules\Shipping\Events\ShipmentReturned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings an order in line with what Shipping reports, and tells the customer. Shipping announces through the outbox and the order follows; each announcement
 * is applied at most once, and one that no longer applies (the order was cancelled meanwhile) is a quiet no-op.
 */
final class ApplyShipmentEvent
{
    public function __construct(private readonly IdempotentConsumer $consumer, private readonly OrderMessages $messages, private readonly OrderCompletion $completion, private readonly Clock $clock) {}

    public function handedOver(ShipmentHandedOver $e): void
    {
        $this->once('orders.shipment_handed_over', $e, function () use ($e): void {
            $this->tell($e->orderId, 'order.shipped.sms', $e);
        });
    }

    public function delivered(ShipmentDelivered $e): void
    {
        $this->once('orders.shipment_delivered', $e, function () use ($e): void {
            if ($e->detail === 'all_delivered') {
                // set once: the last parcel arrived. The database refuses a second value; a repeat changes nothing.
                DB::table('orders')->where('id', $e->orderId)->whereNull('delivered_at')->update(['delivered_at' => CarbonImmutable::parse($e->occurredAt, 'UTC'), 'updated_at' => $this->clock->now()]);
                $this->note($e->orderId, __('orders::notifications.note_delivered'));
            }
            $this->tell($e->orderId, 'order.delivered.sms', $e);
            $this->completion->attempt($e->orderId);
        });
    }

    public function failed(ShipmentFailed $e): void
    {
        $this->once('orders.shipment_failed', $e, function () use ($e): void {
            $final = $e->detail === 'final';
            $this->note($e->orderId, __('orders::notifications.note_delivery_failed', ['attempts' => $e->attempts]));
            $this->tell($e->orderId, $final ? 'order.delivery_failed_final.sms' : 'order.delivery_failed.sms', $e);
        });
    }

    public function returned(ShipmentReturned $e): void
    {
        $this->once('orders.shipment_returned', $e, function () use ($e): void {
            $this->note($e->orderId, __('orders::notifications.note_returned'));
        });
    }

    /** Tells the customer; a repeat of the same announcement is the same message (its key is the announcement's own id), a different one is a new message. */
    private function tell(int $orderId, string $code, DomainEvent $e): void
    {
        $order = Order::query()->find($orderId);
        if ($order !== null) {
            $this->messages->tryToSend($order, $code, [], $code.'|'.($e->outboxId ?? (string) Str::ulid()));
        }
    }

    private function note(int $orderId, string $body): void
    {
        DB::table('order_notes')->insert(['order_id' => $orderId, 'author_id' => null, 'visibility' => 'internal', 'body' => $body, 'created_at' => $this->clock->now()]);
    }

    private function once(string $consumer, DomainEvent $event, callable $work): void
    {
        $this->consumer->once($consumer, $event, $work(...));
    }
}
