<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\PaymentInitiator;
use App\Modules\Orders\Models\Order;

final class CodPaymentInitiator implements PaymentInitiator
{
    public const CODE = 'cod';

    public function method(): string
    {
        return self::CODE;
    }

    public function initiate(Order $order): ?string
    {
        return null; // the courier collects the cash; the order stays unpaid until the delivery is recorded
    }
}
