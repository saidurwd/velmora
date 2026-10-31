<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Inventory\Contracts\ShipmentStock;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Orders\DTOs\ShippableLine;
use App\Modules\Orders\DTOs\ShippableOrder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\CourierAccount;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\ShipmentLog;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes one parcel for an order, whole or in part. In ONE transaction, with the order row locked first (so it cannot be cancelled, or shipped by someone
 * else, at the same moment): the quantities are counted as shipped (never more than ordered), the stock leaves the warehouse for those quantities only,
 * and the parcel with its items and first history entry is written. Booking with the courier follows as a separate step and may fail without losing
 * anything. Who may do this (permission `shipping.create_shipment`) is decided by the caller.
 */
final class CreateShipment
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly ShipmentStock $stock,
        private readonly CourierRegistry $couriers,
        private readonly BookShipment $book,
        private readonly ShipmentLog $log,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<int, int>  $lines  order item id => quantity to put in this parcel
     *
     * @throws ShipmentException
     */
    public function handle(Actor $actor, string $orderPublicId, array $lines, ?string $courierAccountPublicId = null, ?string $note = null): Shipment
    {
        if ($lines === []) {
            throw new ShipmentException('no_lines');
        }
        foreach ($lines as $quantity) {
            if ($quantity < 1) {
                throw new ShipmentException('quantity_invalid');
            }
        }
        $account = $this->account($courierAccountPublicId);
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);

        $shipment = Transactions::run(function () use ($actor, $orderPublicId, $lines, $account, $note): Shipment {
            $order = $this->orders->lock($orderPublicId) ?? throw new ShipmentException('order_not_found');
            if ($order->notReadyReason !== null) {
                throw new ShipmentException($order->notReadyReason);
            }

            $byItem = [];
            foreach ($order->lines as $line) {
                $byItem[$line->orderItemId] = $line;
            }
            $variantQty = [];
            foreach ($lines as $itemId => $quantity) {
                $line = $byItem[$itemId] ?? throw new ShipmentException('line_not_in_order');
                if ($line->productType !== 'physical') {
                    throw new ShipmentException('line_not_shippable');
                }
                if ($quantity > $line->remaining()) {
                    throw new ShipmentException('quantity_exceeds_remaining', ['left' => $line->remaining()]);
                }
                $variantQty[$line->variantId] = ($variantQty[$line->variantId] ?? 0) + $quantity;
            }

            $now = $this->clock->now();
            $publicId = strtolower((string) Str::ulid());
            $shipmentId = DB::table('shipments')->insertGetId([
                'public_id' => $publicId, 'order_id' => $order->orderId, 'courier_account_id' => $account->id, 'courier' => $account->courier, 'status' => 'created',
                'cod_amount_minor' => $this->cashToCollect($order, $lines, $byItem), 'currency' => $order->currency, 'attempts' => 0, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($lines as $itemId => $quantity) {
                DB::table('shipment_items')->insert(['shipment_id' => $shipmentId, 'order_item_id' => $itemId, 'quantity' => $quantity, 'created_at' => $now]);
            }

            try {
                $this->stock->commit($order->publicId, $variantQty, $publicId, $actor->id);
            } catch (DomainException) {
                throw new ShipmentException('stock_not_held'); // the order no longer holds this stock: a person must look
            }
            if (! $this->orders->recordShipped($actor, $order->orderId, $lines, 'Parcel '.$publicId)) {
                throw new ShipmentException('quantity_exceeds_remaining');
            }

            $this->log->add($shipmentId, 'created', 'created', 'manual', $actor->id, $note);
            $shipment = Shipment::query()->findOrFail($shipmentId);
            $this->audit->record('shipment.created', $shipment, [], ['order' => $order->number, 'courier' => $account->courier, 'cod_minor' => $shipment->cod_amount_minor, 'lines' => $lines], $actor);

            return $shipment;
        });

        try {
            return $this->book->handle($actor, $shipment->public_id); // the parcel stands even if the courier cannot be reached
        } catch (ShipmentException) {
            return Shipment::query()->findOrFail($shipment->id);
        }
    }

    /** @throws ShipmentException */
    private function account(?string $publicId): CourierAccount
    {
        $query = CourierAccount::query()->where('is_active', true);
        $account = $publicId === null ? $query->orderBy('sort_order')->orderBy('id')->first() : $query->where('public_id', $publicId)->first();
        if ($account === null) {
            throw new ShipmentException('courier_unavailable');
        }
        try {
            $this->couriers->for($account->courier);
        } catch (CourierException) {
            throw new ShipmentException('courier_unavailable');
        }

        return $account;
    }

    /**
     * The cash this parcel collects at the door. Prepaid orders: nothing. Cash on delivery: the parcel that completes the order takes whatever is left of the
     * total (delivery charge and fee included), an earlier parcel its lines' share rounded down, so the parcels always add up to the order total exactly.
     *
     * @param  array<int, int>  $lines
     * @param  array<int, ShippableLine>  $byItem
     */
    private function cashToCollect(ShippableOrder $order, array $lines, array $byItem): int
    {
        if (! $order->paymentOutstanding) {
            return 0;
        }

        $already = (int) DB::table('shipments')->where('order_id', $order->orderId)->where('status', '!=', 'cancelled')->sum('cod_amount_minor');
        $completes = collect($byItem)->filter(fn (ShippableLine $l) => $l->productType === 'physical')->every(fn (ShippableLine $l) => $l->remaining() === ($lines[$l->orderItemId] ?? 0));
        if ($completes) {
            return max(0, $order->grandTotalMinor - $already);
        }

        $share = 0;
        foreach ($lines as $itemId => $quantity) {
            $share += intdiv($byItem[$itemId]->lineTotalMinor * $quantity, $byItem[$itemId]->quantity);
        }

        return max(0, min($share, $order->grandTotalMinor - $already));
    }
}
