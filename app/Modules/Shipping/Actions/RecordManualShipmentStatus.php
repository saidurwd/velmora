<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff say where a parcel is (a manual parcel, or a courier that cannot be reached): in transit, out for delivery, delivered, failed, returned. It goes
 * through exactly the rules and announcements of a courier's own message, so delivery still records the cash and completes the order, and the person is
 * kept in the parcel's history. Who may do this (`shipping.create_shipment`) is decided by the caller.
 */
final class RecordManualShipmentStatus
{
    public const STATUSES = ['in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];

    public function __construct(private readonly RecordCourierEvent $record, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $shipmentPublicId, string $status, ?string $note = null, ?CarbonImmutable $occurredAt = null): Shipment
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new ShipmentException('status_invalid');
        }
        $shipment = Shipment::query()->where('public_id', $shipmentPublicId)->first() ?? throw new ShipmentException('shipment_not_found');
        if ($shipment->status->isFinal()) {
            throw new ShipmentException($shipment->status->value === 'cancelled' ? 'shipment_cancelled' : 'shipment_finished');
        }
        if ($shipment->status->value === 'created') {
            throw new ShipmentException('hand_over_first');
        }

        $now = $this->clock->now();
        $occurredAt ??= $now;
        if ($occurredAt->greaterThan($now->addMinutes(5))) {
            throw new ShipmentException('time_invalid');
        }
        $latest = DB::table('shipment_events')->where('shipment_id', $shipment->id)->whereIn('internal_status', ['handed_over', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'])->max('occurred_at');
        if ($latest !== null && $occurredAt->lessThan(CarbonImmutable::parse((string) $latest, 'UTC'))) {
            throw new ShipmentException('time_before_latest'); // the parcel's state follows the latest event by time: an older one could change nothing
        }
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);

        if ($this->record->manual($shipment, $status, $occurredAt, $note, (int) $actor->id) === RecordCourierEvent::DUPLICATE) {
            throw new ShipmentException('already_recorded');
        }

        return Shipment::query()->findOrFail($shipment->id);
    }
}
