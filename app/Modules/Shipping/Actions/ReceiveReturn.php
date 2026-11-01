<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use App\Modules\Shipping\Services\ReturnClosing;
use Illuminate\Support\Facades\DB;

/**
 * The goods are back at the shop. Staff say how many of each line really came back (by default all that was asked for); that is counted as returned on the
 * order (never more than was shipped) and the order's state follows. Nothing goes back on the shelf yet: each line is looked at next. A line that did not
 * come back needs no looking at, and a return where nothing came back is closed at once. Order row first, then the return, like everything else.
 */
final class ReceiveReturn
{
    public function __construct(private readonly OrderFulfilment $orders, private readonly OrderDirectory $directory, private readonly ReturnClosing $closing, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<int, int>|null  $received  order item id => quantity that came back (default: everything asked for)
     *
     * @throws ShipmentException
     */
    public function handle(Actor $actor, string $returnPublicId, ?array $received = null): ReturnRequest
    {
        $found = ReturnRequest::query()->where('public_id', $returnPublicId)->first() ?? throw new ShipmentException('return_not_found');
        $orderPublicId = (string) ($this->directory->references([$found->order_id])[$found->order_id]['public_id'] ?? '');

        return Transactions::run(function () use ($actor, $found, $orderPublicId, $received): ReturnRequest {
            $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found');
            $return = ReturnRequest::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== ReturnStatus::Authorized) {
                throw new ShipmentException($return->status === ReturnStatus::Requested ? 'return_not_authorized' : 'return_not_open', ['status' => $return->status->value]);
            }

            $items = DB::table('return_items')->where('return_id', $return->id)->lockForUpdate()->get();
            $back = [];
            foreach ($items as $item) {
                $quantity = $received[(int) $item->order_item_id] ?? (int) $item->quantity;
                if ($quantity < 0 || $quantity > (int) $item->quantity) {
                    throw new ShipmentException('received_invalid', ['max' => (int) $item->quantity]);
                }
                $back[(int) $item->order_item_id] = $quantity;
            }

            $now = $this->clock->now();
            foreach ($items as $item) {
                $quantity = $back[(int) $item->order_item_id];
                DB::table('return_items')->where('id', $item->id)->whereNull('received_qty')->update($quantity === 0 ? ['received_qty' => 0, 'outcome' => 'not_received', 'inspected_by' => $actor->id, 'inspected_at' => $now] : ['received_qty' => $quantity]);
            }
            $counted = array_filter($back, fn (int $q): bool => $q > 0);
            if ($counted !== [] && ! $this->orders->recordReturned($actor, $order->orderId, $counted, 'Return '.$return->public_id)) {
                throw new ShipmentException('return_exceeds_shipped'); // would pass what was shipped: nothing is counted
            }

            DB::table('return_requests')->where('id', $return->id)->where('status', ReturnStatus::Authorized->value)->update(['status' => ReturnStatus::Received->value, 'received_at' => $now, 'received_by' => $actor->id, 'updated_at' => $now]);
            if ($return->kind === 'origin' && $return->shipment_id !== null) {
                // the parcel that came back is no longer waiting for a person
                $shipmentRef = (string) DB::table('shipments')->where('id', $return->shipment_id)->value('public_id');
                DB::table('shipment_exceptions')->where('type', 'returned_to_origin')->where('reference', $shipmentRef)->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => $now, 'resolution' => 'Received back', 'updated_at' => $now]);
            }

            $fresh = ReturnRequest::query()->findOrFail($return->id);
            $this->audit->record('return.received', $fresh, ['status' => 'authorized'], ['status' => 'received', 'received' => $back], $actor);
            $this->closing->closeIfDone($fresh, $order, $actor); // nothing to look at when nothing came back

            return ReturnRequest::query()->findOrFail($return->id);
        });
    }
}
