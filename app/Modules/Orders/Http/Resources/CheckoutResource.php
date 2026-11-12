<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cms\DTOs\PolicyNotice;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Orders\DTOs\CheckoutReview;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;

/** The checkout review for the app: what the order would cost, how it would arrive, how it could be paid, and what must be done first. All of it worked out by the server. */
final class CheckoutResource
{
    public const ADDRESS = 'array{id: string|null, recipient: string, phone: string, division: string, district: string, upazila: string, area: string|null, address_line: string, landmark: string|null, delivery_note: string|null, postal_code: string|null, one_line: string}';

    public const TOTALS = 'array{subtotal: '.ApiTypes::MONEY.', discount: '.ApiTypes::MONEY.', shipping: '.ApiTypes::MONEY.', cod_fee: '.ApiTypes::MONEY.', tax: '.ApiTypes::MONEY.', tax_inclusive: bool, grand_total: '.ApiTypes::MONEY.'}';

    public const TYPE = 'array{contact: array{name: string, phone: string, email: string|null}, address: '.self::ADDRESS.'|null, cart: '.CartPresenter::TYPE.', totals: '.self::TOTALS.', delivery: array{required: bool, can_deliver: bool, zone: string|null, selected: string|null, options: list<array{code: string, name: string, estimate: string|null, price: '.ApiTypes::MONEY.', is_free: bool, cod_available: bool, cod_fee: '.ApiTypes::MONEY.'}>}, payment: array{selected: string, options: list<array{code: string, type: string, provider: string, label: string, description: string|null}>}, requires_phone_confirmation: bool, policies: list<array{kind: string, release_id: int, version: int, title: string, url: string|null, effective_at: string}>}';

    /**
     * @param  list<PolicyNotice>  $policies
     * @return array<string, mixed>
     */
    public static function make(CheckoutReview $r, array $policies, string $locale, CartPresenter $carts): array
    {
        $b = $r->quote->breakdown;
        $money = fn (int $minor): array => MoneyData::from(Money::of($minor, $b->currency));

        return [
            'contact' => ['name' => $r->contactName, 'phone' => $r->contactPhone, 'email' => $r->contactEmail],
            'address' => $r->address === null ? null : self::address($r->address, $locale),
            'cart' => $carts->present($r->cart, null),
            'totals' => [
                'subtotal' => $money($b->subtotalMinor), 'discount' => $money($b->discountMinor()), 'shipping' => $money($b->shippingMinor), 'cod_fee' => $money($b->codFeeMinor),
                'tax' => $money($b->taxMinor), 'tax_inclusive' => $b->taxInclusive, 'grand_total' => $money($b->grandTotalMinor),
            ],
            'delivery' => [
                'required' => $r->quote->shipping->required,
                'can_deliver' => $r->quote->shipping->canDeliver(),
                'zone' => $r->quote->shipping->zoneName,
                'selected' => $r->shippingCode,
                'options' => array_map(fn ($o): array => [
                    'code' => $o->methodCode, 'name' => $o->name, 'estimate' => $o->deliveryEstimate, 'price' => MoneyData::from($o->price),
                    'is_free' => $o->isFree, 'cod_available' => $o->codAvailable, 'cod_fee' => MoneyData::from($o->codFee),
                ], $r->quote->shipping->options),
            ],
            'payment' => [
                'selected' => $r->paymentCode,
                'options' => array_map(fn ($o): array => ['code' => $o->code, 'type' => $o->type, 'provider' => $o->provider, 'label' => $o->label, 'description' => $o->description], $r->quote->paymentOptions),
            ],
            'requires_phone_confirmation' => $r->needsPhoneConfirmation,
            'policies' => array_map(fn (PolicyNotice $p): array => ['kind' => $p->kind, 'release_id' => $p->releaseId, 'version' => $p->versionNumber, 'title' => $p->title, 'url' => $p->url, 'effective_at' => $p->effectiveAt->toIso8601String()], $policies),
        ];
    }

    /** @return array<string, mixed> */
    public static function address(AddressView $a, string $locale): array
    {
        return [
            'id' => $a->publicId !== '' ? $a->publicId : null, 'recipient' => $a->recipient, 'phone' => $a->phone,
            'division' => $a->division[$locale] ?? $a->division['en'], 'district' => $a->district[$locale] ?? $a->district['en'], 'upazila' => $a->upazila[$locale] ?? $a->upazila['en'],
            'area' => $a->area, 'address_line' => $a->addressLine, 'landmark' => $a->landmark, 'delivery_note' => $a->deliveryNote, 'postal_code' => $a->postalCode, 'one_line' => $a->oneLine($locale),
        ];
    }
}
