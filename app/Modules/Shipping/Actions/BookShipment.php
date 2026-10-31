<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\DTOs\ShipmentRequest;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\ShipmentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Books a parcel with its courier and keeps the courier's id and tracking code. It is a separate step on purpose: the parcel (and its stock) exist
 * even when the courier cannot be reached, and staff press "book again" later. A courier that has no booking (the manual one) books nothing.
 */
final class BookShipment
{
    public function __construct(private readonly CourierRegistry $couriers, private readonly OrderFulfilment $orders, private readonly ShipmentLog $log, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $shipmentPublicId): Shipment
    {
        $shipment = Shipment::query()->where('public_id', $shipmentPublicId)->first() ?? throw new ShipmentException('shipment_not_found');
        if ($shipment->status !== ShipmentStatus::Created) {
            throw new ShipmentException('cannot_book_now');
        }
        $provider = $this->couriers->for($shipment->courier);
        if (! $provider->capabilities()->createsConsignment || $shipment->courier_consignment_id !== null) {
            return $shipment; // nothing to book, or already booked
        }

        $request = Transactions::run(fn (): ShipmentRequest => $this->request($shipment));

        try {
            $booking = $provider->createShipment($request); // outside any lock: the courier may be slow
        } catch (CourierException $e) {
            Log::warning('shipping.booking_failed', ['shipment' => $shipment->public_id, 'courier' => $shipment->courier, 'reason' => $e->reason]);
            $this->log->add($shipment->id, 'booking_failed', 'created', 'system', $actor->id, 'The courier could not be reached: '.$e->reason);

            throw new ShipmentException('courier_unreachable', ['reason' => $e->reason]);
        }

        $booked = Transactions::run(function () use ($shipment, $booking, $actor): bool {
            // set once: a second booking (two people pressing the button) cannot replace the first
            $set = DB::table('shipments')->where('id', $shipment->id)->where('status', ShipmentStatus::Created->value)->whereNull('courier_consignment_id')
                ->update(['courier_consignment_id' => $booking->consignmentId, 'tracking_code' => $booking->trackingCode, 'tracking_url' => $booking->trackingUrl, 'updated_at' => $this->clock->now()]);
            if ($set === 1) {
                $this->log->add($shipment->id, 'booked', 'created', 'system', $actor->id, 'Booked with '.$shipment->courier, null, ['consignment' => $booking->consignmentId]);
            }

            return $set === 1;
        });
        if ($booked) {
            $this->audit->record('shipment.booked', $shipment, [], ['courier' => $shipment->courier, 'consignment' => $booking->consignmentId], $actor);
        }

        return Shipment::query()->findOrFail($shipment->id);
    }

    private function request(Shipment $shipment): ShipmentRequest
    {
        $orderPublicId = (string) DB::table('orders')->where('id', $shipment->order_id)->value('public_id');
        $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found');
        $items = DB::table('shipment_items')->where('shipment_id', $shipment->id)->sum('quantity');

        return new ShipmentRequest($shipment->public_id, $order->number, $order->contactName, $order->contactPhone, $order->addressLine, $order->district, $order->area, $order->postalCode, (int) $items, 0, $shipment->cod_amount_minor, $shipment->currency);
    }
}
