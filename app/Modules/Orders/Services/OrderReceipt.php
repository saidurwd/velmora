<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;

/**
 * What an order receipt says, built only from the figures stored on the order (never recalculated). `reconciles` is the accounting identity checked
 * over the stored parts: lines add up to the subtotal, and subtotal − discounts + delivery + cash fee (+ tax when it is added on top) is the total.
 * A receipt that does not reconcile is never given to a customer.
 */
class OrderReceipt
{
    public function __construct(private readonly PaymentMethodCatalog $methods) {}

    /** @return array<string, mixed> */
    public function for(Order $order, string $locale): array
    {
        $order->loadMissing(['items', 'addresses', 'taxLines']);
        $address = $order->addresses->firstWhere('type', 'shipping') ?? $order->addresses->first();

        $lines = [];
        $gross = 0;
        foreach ($order->items as $item) {
            $gross += $item->unit_price_minor * $item->quantity;
            $lines[] = [
                'name' => $item->name($locale), 'options' => $item->options($locale), 'sku' => $item->sku_snapshot, 'quantity' => $item->quantity, 'unit_minor' => $item->unit_price_minor,
                'discount_minor' => $item->discount_minor, 'tax_minor' => $item->tax_minor, 'total_minor' => $item->line_total_minor,
            ];
        }

        $expected = $order->subtotal_minor - $order->discount_minor + $order->shipping_minor + $order->cod_fee_minor + ($order->tax_inclusive ? 0 : $order->tax_minor);

        return [
            'number' => $order->order_number, 'placed_at' => $order->placed_at, 'currency' => $order->currency, 'cancelled' => $order->cancelled_at !== null,
            'customer' => ['name' => $order->contact_name, 'email' => $order->contact_email],
            'address' => $address === null ? null : ['name' => $address->recipient_name, 'line' => $address->address_line, 'area' => $address->area, 'postal_code' => $address->postal_code, 'district' => $address->location_snapshot['district'][$locale] ?? $address->location_snapshot['district']['en'] ?? ''],
            'payment' => ['method' => $this->methods->label($order->payment_method_code, $locale), 'status' => $order->payment_status->value],
            'lines' => $lines,
            'totals' => [
                'subtotal_minor' => $order->subtotal_minor, 'discount_minor' => $order->discount_minor, 'shipping_minor' => $order->shipping_minor, 'cod_fee_minor' => $order->cod_fee_minor,
                'tax_minor' => $order->tax_minor, 'tax_inclusive' => $order->tax_inclusive, 'grand_minor' => $order->grand_total_minor,
            ],
            'tax_lines' => $order->taxLines->map(fn ($t): array => ['kind' => $t->kind, 'rate_bp' => $t->rate_bp, 'taxable_minor' => $t->taxable_minor, 'tax_minor' => $t->tax_minor])->all(),
            'reconciles' => $gross === $order->subtotal_minor && $expected === $order->grand_total_minor,
        ];
    }
}
