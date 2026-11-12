<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderAddress;
use App\Modules\Orders\Models\OrderCancellationRequest;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\CancellationRequestRules;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;

/**
 * One order for the app: what was bought and at what price (frozen at the time of the order), where it goes, how it was paid, and `available_actions`, the things
 * the person may do next, worked out by the server with the website's own rules so the app never decides them. A guest holding the order's token sees the same,
 * with the phone number masked and without the owner-only actions.
 */
final class OrderDetail
{
    public const ITEM = 'array{name: string, sku: string, options: string|null, type: string, quantity: int, unit_price: '.ApiTypes::MONEY.', discount: '.ApiTypes::MONEY.', tax: '.ApiTypes::MONEY.', line_total: '.ApiTypes::MONEY.'}';

    public const TYPE = 'array{id: string, number: string, status: string, payment_status: string, fulfillment_status: string, payment_method: string, shipping_method: string|null, placed_at: string, totals: array{subtotal: '.ApiTypes::MONEY.', discount: '.ApiTypes::MONEY.', shipping: '.ApiTypes::MONEY.', cod_fee: '.ApiTypes::MONEY.', tax: '.ApiTypes::MONEY.', tax_inclusive: bool, grand_total: '.ApiTypes::MONEY.'}, contact: array{name: string, phone: string, email: string|null}, items: list<'.self::ITEM.'>, delivery_address: array{recipient: string, phone: string, division: string|null, district: string|null, upazila: string|null, area: string|null, address_line: string, landmark: string|null, delivery_note: string|null, postal_code: string|null}|null, payment: array{method: string, label: string, online: bool, status: string}, cancellation_request: string|null, available_actions: list<string>}';

    /** @return array<string, mixed> */
    public static function make(Order $o, bool $isOwner, string $locale, PaymentMethodCatalog $methods, bool $payable): array
    {
        $o->loadMissing(['items', 'addresses']);
        $online = $o->payment_method_code !== 'cod';
        $phone = fn (string $p): string => $isOwner ? $p : (PhoneNumber::tryParse($p)?->masked() ?? '');
        $money = fn (int $minor): array => MoneyData::from(Money::of($minor, $o->currency));
        $address = $o->addresses->firstWhere('type', 'shipping');
        $request = OrderCancellationRequest::query()->where('order_id', $o->id)->latest('id')->first();   // index (order_id, id)

        return OrderSummary::make($o) + [
            'contact' => ['name' => $o->contact_name, 'phone' => $phone($o->contact_phone), 'email' => $isOwner ? $o->contact_email : null],
            'items' => $o->items->map(fn (OrderItem $i): array => [
                'name' => $i->name($locale), 'sku' => $i->sku_snapshot, 'options' => $i->options_snapshot[$locale] ?? ($i->options_snapshot['en'] ?? null), 'type' => $i->product_type, 'quantity' => $i->quantity,
                'unit_price' => $money($i->unit_price_minor), 'discount' => $money($i->discount_minor), 'tax' => $money($i->tax_minor), 'line_total' => $money($i->line_total_minor),
            ])->values()->all(),
            'delivery_address' => $address instanceof OrderAddress ? [
                'recipient' => $address->recipient_name, 'phone' => $phone($address->phone),
                'division' => self::place($address, 'division', $locale), 'district' => self::place($address, 'district', $locale), 'upazila' => self::place($address, 'upazila', $locale),
                'area' => $address->area, 'address_line' => $address->address_line, 'landmark' => $address->landmark, 'delivery_note' => $address->delivery_note, 'postal_code' => $address->postal_code,
            ] : null,
            'payment' => ['method' => $o->payment_method_code, 'label' => $methods->label($o->payment_method_code, $locale), 'online' => $online, 'status' => $o->payment_status->value],
            'cancellation_request' => $request?->status,
            'available_actions' => self::actions($o, $isOwner, $online, $payable, $request?->status),
        ];
    }

    /** @return list<string> */
    private static function actions(Order $o, bool $isOwner, bool $online, bool $payable, ?string $requestStatus): array
    {
        $actions = ['invoice'];
        $payable && $online && $actions[] = 'pay';
        $o->fulfillment_status->hasShipped() && $actions[] = 'track';

        if ($isOwner) {
            CancelOwnOrder::deniedReason($o) === null && $actions[] = 'cancel';
            CancellationRequestRules::deniedReason($o) === null && $requestStatus !== 'requested' && $actions[] = 'request_cancel';
            $actions[] = 'reorder';
            $o->delivered_at !== null && $actions[] = 'return'; // a hint: GET /orders/{id}/returns/eligibility says which lines may go back
        }

        return $actions;
    }

    private static function place(OrderAddress $a, string $level, string $locale): ?string
    {
        $names = $a->location_snapshot[$level] ?? null;

        return is_array($names) ? ($names[$locale] ?? $names['en'] ?? null) : null;
    }
}
