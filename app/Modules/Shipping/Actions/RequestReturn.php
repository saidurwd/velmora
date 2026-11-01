<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks to send goods back. A customer asks for their own order, within the return window counted from the day the parcel arrived; staff may ask at any
 * time. Only goods that were delivered can come back, and never more than were delivered less what is already back or already asked for (counted with the
 * order row locked, so two requests at the same instant cannot both take the last unit). Someone else's order is "not found".
 */
final class RequestReturn
{
    public const REASONS = ['wrong_item', 'damaged', 'not_as_described', 'changed_mind', 'other'];

    public function __construct(private readonly OrderFulfilment $orders, private readonly Settings $settings, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<int, int>  $lines  order item id => quantity to send back
     *
     * @throws ShipmentException
     */
    public function handle(Actor $actor, string $orderPublicId, array $lines, string $reasonCode, ?string $reason): ReturnRequest
    {
        if (! in_array($reasonCode, self::REASONS, true)) {
            throw new ShipmentException('reason_invalid');
        }
        if ($lines === []) {
            throw new ShipmentException('no_lines');
        }
        foreach ($lines as $quantity) {
            if ($quantity < 1) {
                throw new ShipmentException('quantity_invalid');
            }
        }
        $reason = $reason === null ? null : (mb_substr(trim(BanglaText::input($reason)), 0, 255) ?: null);
        $customer = $actor->type === ActorType::Customer;
        if (! $customer && $actor->type !== ActorType::Staff) {
            throw new ShipmentException('order_not_found'); // a guest or a system process cannot start a customer's return
        }

        $return = Transactions::run(function () use ($actor, $orderPublicId, $lines, $reasonCode, $reason, $customer): ReturnRequest {
            $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found');
            if ($customer && ($actor->id === null || $order->ownerUserId !== $actor->id)) {
                throw new ShipmentException('order_not_found'); // never reveal that someone else's order exists
            }

            $byItem = [];
            foreach ($order->lines as $line) {
                $byItem[$line->orderItemId] = $line;
            }
            $window = (int) $this->settings->int('shipping.return_window_days', (int) config('shipping.return_window_days'));
            $now = $this->clock->now();

            foreach ($lines as $itemId => $quantity) {
                $line = $byItem[$itemId] ?? throw new ShipmentException('line_not_in_order');
                if ($line->productType !== 'physical') {
                    throw new ShipmentException('line_not_shippable');
                }

                $delivered = (int) DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')->where('s.order_id', $order->orderId)->where('s.status', 'delivered')->where('si.order_item_id', $itemId)->sum('si.quantity');
                if ($delivered < 1) {
                    throw new ShipmentException('not_delivered');
                }
                $asked = (int) DB::table('return_items as ri')->join('return_requests as rr', 'rr.id', '=', 'ri.return_id')->where('ri.order_item_id', $itemId)->whereIn('rr.status', ['requested', 'authorized'])->sum('ri.quantity');
                $left = $delivered - $line->returnedQty - $asked;
                if ($quantity > $left) {
                    throw new ShipmentException('return_exceeds_delivered', ['left' => max(0, $left)]);
                }

                if ($customer) {
                    $arrived = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')->where('s.order_id', $order->orderId)->where('s.status', 'delivered')->where('si.order_item_id', $itemId)->max('s.delivered_at');
                    if ($arrived !== null && $now->greaterThan(CarbonImmutable::parse((string) $arrived, 'UTC')->addDays($window))) {
                        throw new ShipmentException('return_window_closed', ['days' => $window]);
                    }
                }
            }

            $id = DB::table('return_requests')->insertGetId([
                'public_id' => strtolower((string) Str::ulid()), 'order_id' => $order->orderId, 'shipment_id' => null, 'kind' => 'customer', 'status' => 'requested', 'reason_code' => $reasonCode, 'reason' => $reason,
                'requested_by_type' => $customer ? 'customer' : 'staff', 'requested_by' => $actor->id, 'shipping_cost_payer' => 'none', 'currency' => $order->currency, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($lines as $itemId => $quantity) {
                DB::table('return_items')->insert(['return_id' => $id, 'order_item_id' => $itemId, 'quantity' => $quantity, 'created_at' => $now]);
            }
            $return = ReturnRequest::query()->findOrFail($id);
            $this->audit->record('return.requested', $return, [], ['order' => $order->number, 'reason_code' => $reasonCode, 'lines' => $lines], $actor);

            return $return;
        });

        return $return;
    }
}
