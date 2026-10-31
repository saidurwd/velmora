<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Inventory\Contracts\ShipmentStock;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\ShipmentLog;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a parcel that has not left the shop (a wrong parcel is cancelled and a new one made): the courier is told first when it had booked it, then in ONE
 * transaction (order row locked first) the stock goes back into the order's hold, the shipped counts go back down and the order's fulfilment state follows.
 * After handover there is nothing to cancel: the parcel is followed to delivery or return.
 */
final class CancelShipment
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly ShipmentStock $stock,
        private readonly CourierRegistry $couriers,
        private readonly ShipmentLog $log,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $shipmentPublicId, string $reason): Shipment
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new ShipmentException('reason_required');
        }

        $shipment = Shipment::query()->where('public_id', $shipmentPublicId)->first() ?? throw new ShipmentException('shipment_not_found');
        $this->assertCancellable($shipment);

        // tell the courier first, outside any lock: if it refuses we stop and nothing has changed here
        if ($shipment->courier_consignment_id !== null && $this->couriers->for($shipment->courier)->capabilities()->cancel) {
            try {
                $this->couriers->for($shipment->courier)->cancelShipment($shipment->courier_consignment_id);
            } catch (CourierException $e) {
                throw new ShipmentException('courier_unreachable', ['reason' => $e->reason]);
            }
        }

        $done = Transactions::run(function () use ($actor, $shipment, $reason): Shipment {
            $orderPublicId = (string) DB::table('orders')->where('id', $shipment->order_id)->value('public_id');
            $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found'); // order first, then the parcel (the same order as making one)
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($locked);

            $quantities = DB::table('shipment_items')->where('shipment_id', $locked->id)->pluck('quantity', 'order_item_id')->map(fn ($q): int => (int) $q)->all();
            $moved = DB::table('shipments')->where('id', $locked->id)->where('status', ShipmentStatus::Created->value)->update(['status' => ShipmentStatus::Cancelled->value, 'updated_at' => $this->clock->now()]);
            if ($moved !== 1) {
                throw new ShipmentException('cannot_cancel_after_handover'); // it left the shop in the same instant
            }
            $this->stock->revert($order->publicId, $locked->public_id, $actor->id);
            if (! $this->orders->recordUnshipped($actor, $order->orderId, $quantities, 'Parcel '.$locked->public_id.' cancelled')) {
                throw new ShipmentException('cannot_cancel_now');
            }
            $this->log->add($locked->id, 'cancelled', 'cancelled', 'manual', $actor->id, $reason);

            return Shipment::query()->findOrFail($locked->id);
        });
        $this->audit->record('shipment.cancelled', $done, ['status' => 'created'], ['status' => 'cancelled', 'reason' => $reason], $actor);

        return $done;
    }

    /** @throws ShipmentException */
    private function assertCancellable(Shipment $shipment): void
    {
        if ($shipment->status === ShipmentStatus::Cancelled) {
            throw new ShipmentException('shipment_cancelled');
        }
        if ($shipment->status !== ShipmentStatus::Created) {
            throw new ShipmentException('cannot_cancel_after_handover');
        }
    }
}
