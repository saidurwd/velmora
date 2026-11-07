<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/**
 * What orders placed in one time window add up to, for one channel, payment method, language and currency. Orders that were cancelled are not in the money or the
 * order count; they are counted apart (`cancelledOrders`, `cancelledBilledMinor`). `grossMinor` is the price of the goods before any discount (the order's subtotal),
 * `discountsMinor` every discount (line and order level), so gross minus discounts is what the lines add up to.
 */
final readonly class SalesRow
{
    public function __construct(
        public string $channel,
        public string $paymentMethod,
        public string $locale,
        public string $currency,
        public int $orders,
        public int $grossMinor,
        public int $discountsMinor,
        public int $shippingMinor,
        public int $codFeeMinor,
        public int $taxMinor,
        public int $billedMinor,
        public int $cancelledOrders,
        public int $cancelledBilledMinor,
    ) {}
}
