<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Inventory\Contracts\ReturnStock;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Enums\ReturnOutcome;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use App\Modules\Shipping\Services\ReturnClosing;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Looks at one line of goods that came back and decides: restock (back on the shelf where it left from), quarantine (kept apart, not for sale) or write off
 * (counted in and out again so the ledger shows it). The decision is set once (one conditional UPDATE), writes its ledger rows in the same transaction,
 * and when the last line is looked at the return closes with a refund SUGGESTION for a person: nothing is ever refunded from here.
 */
final class InspectReturnItem
{
    public function __construct(private readonly OrderFulfilment $orders, private readonly OrderDirectory $directory, private readonly ReturnStock $stock, private readonly ReturnClosing $closing, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $returnPublicId, int $orderItemId, string $outcome, ?string $note = null): ReturnRequest
    {
        $decision = ReturnOutcome::tryFrom($outcome);
        if ($decision === null || ! $decision->touchesStock()) {
            throw new ShipmentException('outcome_invalid');
        }
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);

        $found = ReturnRequest::query()->where('public_id', $returnPublicId)->first() ?? throw new ShipmentException('return_not_found');
        $orderPublicId = (string) ($this->directory->references([$found->order_id])[$found->order_id]['public_id'] ?? '');

        return Transactions::run(function () use ($actor, $found, $orderPublicId, $orderItemId, $decision, $note): ReturnRequest {
            $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found');
            $return = ReturnRequest::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== ReturnStatus::Received) {
                throw new ShipmentException('return_not_received', ['status' => $return->status->value]);
            }

            $item = DB::table('return_items')->where('return_id', $return->id)->where('order_item_id', $orderItemId)->lockForUpdate()->first() ?? throw new ShipmentException('line_not_in_return');
            if ($item->outcome !== null) {
                throw new ShipmentException('already_inspected');
            }
            $quantity = (int) $item->received_qty;
            $line = collect($order->lines)->firstWhere('orderItemId', $orderItemId) ?? throw new ShipmentException('line_not_in_order');

            $now = $this->clock->now();
            $set = DB::table('return_items')->where('id', $item->id)->whereNull('outcome')->update(['outcome' => $decision->value, 'inspected_by' => $actor->id, 'inspected_at' => $now, 'note' => $note]);
            if ($set !== 1) {
                throw new ShipmentException('already_inspected');
            }

            try {
                $this->stock->receive($return->public_id, $line->variantId, $quantity, $decision->value, $this->parcelOf($return, $orderItemId), $actor->id);
            } catch (DomainException) {
                throw new ShipmentException('stock_not_held');
            }
            $this->audit->record('return.inspected', $return, [], ['line' => $orderItemId, 'outcome' => $decision->value, 'quantity' => $quantity], $actor);
            $this->closing->closeIfDone($return, $order, $actor);

            return ReturnRequest::query()->findOrFail($return->id);
        });
    }

    /** The parcel the goods went out in: the returned parcel itself, or the latest delivered one that carried the line. */
    private function parcelOf(ReturnRequest $return, int $orderItemId): ?string
    {
        if ($return->shipment_id !== null) {
            return (string) DB::table('shipments')->where('id', $return->shipment_id)->value('public_id');
        }
        $ref = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')->where('s.order_id', $return->order_id)->where('si.order_item_id', $orderItemId)->where('s.status', 'delivered')->orderByDesc('s.delivered_at')->value('s.public_id');

        return $ref === null ? null : (string) $ref;
    }
}
