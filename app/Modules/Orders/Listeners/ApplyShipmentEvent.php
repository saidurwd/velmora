<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CustomerSms;
use App\Modules\Orders\Services\OrderCompletion;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\DomainEvent;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Shipping\Events\ShipmentDelivered;
use App\Modules\Shipping\Events\ShipmentFailed;
use App\Modules\Shipping\Events\ShipmentHandedOver;
use App\Modules\Shipping\Events\ShipmentReturned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brings an order in line with what Shipping reports, and tells the customer. Shipping announces through the outbox and the order follows; each announcement
 * is applied at most once, and one that no longer applies (the order was cancelled meanwhile) is a quiet no-op.
 */
final class ApplyShipmentEvent
{
    public function __construct(private readonly IdempotentConsumer $consumer, private readonly CustomerSms $sms, private readonly OrderCompletion $completion, private readonly Clock $clock) {}

    public function handedOver(ShipmentHandedOver $e): void
    {
        $this->once('orders.shipment_handed_over', $e, function () use ($e): void {
            $this->tell($e->orderId, 'sms_shipped', [], 'order_shipped');
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
            $this->tell($e->orderId, 'sms_delivered', [], 'order_delivered');
            $this->completion->attempt($e->orderId);
        });
    }

    public function failed(ShipmentFailed $e): void
    {
        $this->once('orders.shipment_failed', $e, function () use ($e): void {
            $final = $e->detail === 'final';
            $this->note($e->orderId, __('orders::notifications.note_delivery_failed', ['attempts' => $e->attempts]));
            $this->tell($e->orderId, $final ? 'sms_delivery_failed_final' : 'sms_delivery_failed', [], 'order_delivery_failed');
        });
    }

    public function returned(ShipmentReturned $e): void
    {
        $this->once('orders.shipment_returned', $e, function () use ($e): void {
            $this->note($e->orderId, __('orders::notifications.note_returned'));
        });
    }

    /** @param  array<string, scalar>  $params */
    private function tell(int $orderId, string $key, array $params, string $purpose): void
    {
        $order = Order::query()->find($orderId);
        if ($order !== null) {
            $this->sms->send($order, $key, $params, $purpose);
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
