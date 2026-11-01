<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Orders\DTOs\ShippableLine;
use App\Modules\Orders\DTOs\ShippableOrder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;

/** Closes a return once every line has been looked at, and works out what the goods that came back were worth (a suggestion for a person, never executed). */
final class ReturnClosing
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** Returns true when the return was closed by this call. Run inside the caller's transaction, with the return row locked. */
    public function closeIfDone(ReturnRequest $return, ShippableOrder $order, Actor $actor): bool
    {
        if ($return->status !== ReturnStatus::Received) {
            return false;
        }
        $items = DB::table('return_items')->where('return_id', $return->id)->get();
        if ($items->contains(fn ($i): bool => $i->outcome === null)) {
            return false; // a line still to be looked at
        }

        $byItem = [];
        foreach ($order->lines as $line) {
            $byItem[$line->orderItemId] = $line;
        }
        $suggested = 0;
        foreach ($items as $i) {
            /** @var ShippableLine|null $line */
            $line = $byItem[(int) $i->order_item_id] ?? null;
            if ($line !== null && $i->outcome !== 'not_received' && (int) $i->received_qty > 0) {
                $suggested += intdiv($line->lineTotalMinor * (int) $i->received_qty, $line->quantity); // what was paid for those units, rounded down
            }
        }
        // a parcel that never arrived and was to be paid in cash at the door: nothing was paid, so nothing is suggested
        if ($return->kind === 'origin' && $return->shipment_id !== null && (int) DB::table('shipments')->where('id', $return->shipment_id)->value('cod_amount_minor') > 0) {
            $suggested = 0;
        }

        $now = $this->clock->now();
        $closed = DB::table('return_requests')->where('id', $return->id)->where('status', ReturnStatus::Received->value)->update(['status' => ReturnStatus::Closed->value, 'closed_at' => $now, 'refund_suggested_minor' => $suggested, 'updated_at' => $now]);
        if ($closed === 1) {
            $this->audit->record('return.closed', $return, ['status' => 'received'], ['status' => 'closed', 'refund_suggested_minor' => $suggested], $actor);
        }

        return $closed === 1;
    }
}
