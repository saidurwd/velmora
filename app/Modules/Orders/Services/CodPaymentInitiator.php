<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\PaymentInitiator;
use App\Modules\Orders\DTOs\OrderPaymentRequest;

final class CodPaymentInitiator implements PaymentInitiator
{
    public const CODE = 'cod';

    public function supports(string $methodCode): bool
    {
        return $methodCode === self::CODE;
    }

    public function initiate(OrderPaymentRequest $order): ?string
    {
        return null; // the courier collects the cash; the order stays unpaid until the delivery is recorded
    }
}
