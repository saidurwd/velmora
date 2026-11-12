<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;

/** An order in brief, as the app sees it (the full order, with lines and tracking, is the next slice). Enums are stable strings; no internal ids. */
final class OrderSummary
{
    public const TYPE = 'array{id: string, number: string, status: string, payment_status: string, fulfillment_status: string, payment_method: string, shipping_method: string|null, placed_at: string, totals: array{subtotal: '.ApiTypes::MONEY.', discount: '.ApiTypes::MONEY.', shipping: '.ApiTypes::MONEY.', cod_fee: '.ApiTypes::MONEY.', tax: '.ApiTypes::MONEY.', tax_inclusive: bool, grand_total: '.ApiTypes::MONEY.'}}';

    /** @return array<string, mixed> */
    public static function make(Order $o): array
    {
        $money = fn (int $minor): array => MoneyData::from(Money::of($minor, $o->currency));

        return [
            'id' => $o->public_id,
            'number' => $o->order_number,
            'status' => $o->status->value,
            'payment_status' => $o->payment_status->value,
            'fulfillment_status' => $o->fulfillment_status->value,
            'payment_method' => $o->payment_method_code,
            'shipping_method' => $o->shipping_method_code,
            'placed_at' => $o->placed_at->toIso8601String(),
            'totals' => [
                'subtotal' => $money($o->subtotal_minor), 'discount' => $money($o->discount_minor), 'shipping' => $money($o->shipping_minor), 'cod_fee' => $money($o->cod_fee_minor),
                'tax' => $money($o->tax_minor), 'tax_inclusive' => $o->tax_inclusive, 'grand_total' => $money($o->grand_total_minor),
            ],
        ];
    }
}
