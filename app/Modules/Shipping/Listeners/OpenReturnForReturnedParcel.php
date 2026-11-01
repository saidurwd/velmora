<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Listeners;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Shipping\Events\ShipmentReturned;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A parcel the courier could not deliver is coming back: a return is opened for everything in it, already allowed (nobody has to approve what the courier is
 * bringing), waiting to be received and looked at. Once per parcel, however many times the announcement is delivered.
 */
final class OpenReturnForReturnedParcel
{
    public function __construct(private readonly IdempotentConsumer $consumer, private readonly Clock $clock) {}

    public function handle(ShipmentReturned $e): void
    {
        $this->consumer->once('shipping.return_to_origin', $e, function () use ($e): void {
            $now = $this->clock->now();
            try {
                $id = DB::table('return_requests')->insertGetId([
                    'public_id' => strtolower((string) Str::ulid()), 'order_id' => $e->orderId, 'shipment_id' => $e->shipmentId, 'kind' => 'origin', 'status' => 'authorized', 'reason_code' => 'undelivered',
                    'reason' => 'The courier could not deliver the parcel and is bringing it back', 'requested_by_type' => 'system', 'decided_at' => $now, 'shipping_cost_payer' => 'shop', 'currency' => $e->currency, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                return; // already opened for this parcel
            }
            foreach (DB::table('shipment_items')->where('shipment_id', $e->shipmentId)->get() as $item) {
                DB::table('return_items')->insert(['return_id' => $id, 'order_item_id' => $item->order_item_id, 'quantity' => $item->quantity, 'created_at' => $now]);
            }
        });
    }
}
