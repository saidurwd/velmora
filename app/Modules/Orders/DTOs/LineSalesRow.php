<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/**
 * What the lines of the orders placed in one time window add up to for one product variant, per channel, payment method, language and currency. Cancelled orders are left out.
 * `grossMinor` is price x quantity before any discount, `discountsMinor` every discount on those lines (the order's own discount is shared out over its lines), so
 * gross minus discounts is what the lines add up to. `sku` is the one written on the order line when it was sold (it survives a product being removed).
 */
final readonly class LineSalesRow
{
    public function __construct(
        public int $variantId,
        public string $sku,
        public string $channel,
        public string $paymentMethod,
        public string $locale,
        public string $currency,
        public int $orders,
        public int $units,
        public int $returnedUnits,
        public int $grossMinor,
        public int $discountsMinor,
    ) {}
}
