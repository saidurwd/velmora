<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Orders\Models\Order;

/** Builds the payment view of an order from its stored snapshot (no catalog, no live prices). */
final class OrderPaymentRequests
{
    public function for(Order $order): OrderPaymentRequest
    {
        $order->loadMissing(['items', 'addresses']);
        $address = $order->addresses->firstWhere('type', 'shipping');
        $locale = $order->locale;

        $names = $order->items->map(fn ($i) => $i->name($locale).' ×'.$i->quantity)->take(3)->join(', ');
        if ($order->items->count() > 3) {
            $names .= ' …';
        }

        return new OrderPaymentRequest(
            $order->id, $order->public_id, $order->order_number, $order->grand_total_minor, $order->currency, $order->payment_method_code,
            $order->contact_name, $order->contact_phone, $order->contact_email,
            $address?->address_line, $address === null ? null : ($address->location_snapshot['district'][$locale] ?? $address->location_snapshot['district']['en'] ?? null), $address?->postal_code,
            (int) $order->items->sum('quantity'), $address !== null, mb_substr($names, 0, 200), $locale,
        );
    }
}
