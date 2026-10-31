<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Orders\DTOs\ShippableLine;
use App\Modules\Orders\DTOs\ShippableOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderFulfilment implements OrderFulfilment
{
    public function __construct(private readonly TransitionOrder $transition, private readonly Clock $clock) {}

    public function lock(string $orderPublicId): ?ShippableOrder
    {
        $order = Order::query()->where('public_id', $orderPublicId)->lockForUpdate()->first();
        if ($order === null) {
            return null;
        }

        $address = $order->addresses()->where('type', 'shipping')->first();
        $lines = array_values($order->items()->get()->map(fn ($i): ShippableLine => new ShippableLine($i->id, $i->variant_id, $i->product_type, $i->name('en'), $i->sku_snapshot, $i->quantity, $i->fulfilled_qty, $i->returned_qty, $i->line_total_minor))->all());
        $cod = $order->payment_method_code === CodPaymentInitiator::CODE;

        return new ShippableOrder(
            $order->id, $order->public_id, $order->order_number, $order->payment_method_code, $order->payment_status === PaymentStatus::Unpaid && $cod, $order->fulfillment_status, $order->grand_total_minor, $order->currency,
            $address->recipient_name ?? $order->contact_name, $address->phone ?? $order->contact_phone, $address->address_line ?? '', (string) ($address?->location_snapshot['district']['en'] ?? ''), $address?->area, $address?->postal_code,
            $lines, $this->notReady($order, $cod),
        );
    }

    public function recordShipped(Actor $actor, int $orderId, array $quantities, string $reason): bool
    {
        ksort($quantities);
        $stamp = $this->clock->now();
        foreach ($quantities as $itemId => $quantity) {
            // one conditional UPDATE decides: never more shipped than ordered
            $affected = DB::update('UPDATE order_items SET fulfilled_qty = fulfilled_qty + ?, updated_at = ? WHERE id = ? AND order_id = ? AND fulfilled_qty + ? <= quantity', [$quantity, $stamp, $itemId, $orderId, $quantity]);
            if ($affected !== 1) {
                return false;
            }
        }

        $this->syncFulfilment($actor, $orderId, $reason);
        $order = Order::query()->findOrFail($orderId);
        if ($order->status === OrderStatus::Confirmed) {
            $this->transition->status($actor, $orderId, OrderStatus::Processing, $reason);
        }

        return true;
    }

    public function recordUnshipped(Actor $actor, int $orderId, array $quantities, string $reason): bool
    {
        ksort($quantities);
        $stamp = $this->clock->now();
        foreach ($quantities as $itemId => $quantity) {
            $affected = DB::update('UPDATE order_items SET fulfilled_qty = fulfilled_qty - ?, updated_at = ? WHERE id = ? AND order_id = ? AND fulfilled_qty - ? >= returned_qty', [$quantity, $stamp, $itemId, $orderId, $quantity]);
            if ($affected !== 1) {
                return false;
            }
        }

        $this->syncFulfilment($actor, $orderId, $reason);

        return true;
    }

    /** The fulfilment state follows the counts: everything shipped, something shipped, nothing shipped (physical lines only). */
    private function syncFulfilment(Actor $actor, int $orderId, string $reason): void
    {
        $order = Order::query()->findOrFail($orderId);
        if (! in_array($order->fulfillment_status, [FulfillmentStatus::Unfulfilled, FulfillmentStatus::PartiallyFulfilled, FulfillmentStatus::Fulfilled], true)) {
            return; // returns have their own states
        }

        $lines = $order->items()->where('product_type', 'physical')->get();
        $target = match (true) {
            $lines->isNotEmpty() && $lines->every(fn ($l): bool => $l->fulfilled_qty === $l->quantity) => FulfillmentStatus::Fulfilled,
            $lines->contains(fn ($l): bool => $l->fulfilled_qty > 0) => FulfillmentStatus::PartiallyFulfilled,
            default => FulfillmentStatus::Unfulfilled,
        };

        if ($target !== $order->fulfillment_status) {
            $this->transition->fulfillment($actor, $orderId, $target, $reason);
        }
    }

    private function notReady(Order $order, bool $cod): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Cancelled => 'order_cancelled',
            ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true) => 'order_not_confirmed',
            $order->needs_review => 'order_under_review',
            ! $cod && ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true) => 'order_not_paid',
            default => null,
        };
    }
}
