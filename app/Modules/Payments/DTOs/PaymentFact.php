<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** Money that came in for an order (a captured payment), or went back out (a succeeded refund): which order, by which payment method, how much. */
final readonly class PaymentFact
{
    public function __construct(public int $orderId, public string $methodCode, public string $currency, public int $amountMinor) {}
}
