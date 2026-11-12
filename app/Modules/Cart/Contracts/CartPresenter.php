<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartView;
use App\Modules\Platform\Http\Resources\ApiTypes;

/** The cart as the API shows it, for other modules' answers that carry a cart (the checkout review). `TYPE` is its shape for the API document. */
interface CartPresenter
{
    public const LINE = 'array{variant_id: string, sku: string, name: string, options: string|null, product_slug: string, image: string|null, type: string, quantity: int, min_qty: int, max_qty: int|null, qty_step: int, unit_price: '.ApiTypes::MONEY.'|null, line_total: '.ApiTypes::MONEY.'|null, price_changed_from: '.ApiTypes::MONEY.'|null, problem: string|null, available: int|null}';

    public const TYPE = 'array{token: string|null, lines: list<'.self::LINE.'>, item_count: int, subtotal: '.ApiTypes::MONEY.', discount_total: '.ApiTypes::MONEY.', goods_total: '.ApiTypes::MONEY.', free_shipping: bool, discounts: list<array{kind: string, code: string|null, label: string, amount: '.ApiTypes::MONEY.', free_shipping: bool}>, rejected_codes: list<array{code: string, reason: string}>, coupons: list<string>, has_problems: bool, has_price_changes: bool}';

    /** @return array<string, mixed> */
    public function present(CartView $cart, ?string $token): array;
}
