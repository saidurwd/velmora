<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\ShipmentLog;
use Illuminate\Support\Facades\DB;

/**
 * The parcel leaves the shop: handed to the courier (or the shop's own rider). From now on it can no longer be cancelled, only followed to its end. A parcel
 * that must be booked with its courier cannot leave before the booking exists. Who may do this (`shipping.create_shipment`) is decided by the caller.
 */
final class HandOverShipment
{
    public function __construct(private readonly CourierRegistry $couriers, private readonly ShipmentLog $log, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $shipmentPublicId): Shipment
    {
        $shipment = Transactions::run(function () use ($actor, $shipmentPublicId): Shipment {
            $shipment = Shipment::query()->where('public_id', $shipmentPublicId)->lockForUpdate()->first() ?? throw new ShipmentException('shipment_not_found');
            if ($shipment->status !== ShipmentStatus::Created) {
                throw new ShipmentException($shipment->status === ShipmentStatus::Cancelled ? 'shipment_cancelled' : 'already_handed_over');
            }
            if ($this->couriers->for($shipment->courier)->capabilities()->createsConsignment && $shipment->courier_consignment_id === null) {
                throw new ShipmentException('booking_missing');
            }

            $now = $this->clock->now();
            $moved = DB::table('shipments')->where('id', $shipment->id)->where('status', ShipmentStatus::Created->value)->update(['status' => ShipmentStatus::HandedOver->value, 'handed_over_at' => $now, 'updated_at' => $now]);
            if ($moved !== 1) {
                throw new ShipmentException('shipment_cancelled'); // it was cancelled in the same instant
            }
            $this->log->add($shipment->id, 'handed_over', 'handed_over', 'manual', $actor->id);

            return Shipment::query()->findOrFail($shipment->id);
        });
        $this->audit->record('shipment.handed_over', $shipment, ['status' => 'created'], ['status' => 'handed_over'], $actor);

        return $shipment;
    }
}
