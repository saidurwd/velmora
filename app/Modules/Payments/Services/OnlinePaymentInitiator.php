<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Contracts\PaymentInitiator;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Models\PaymentMethod;

/** Starts payment for every method that is not cash. */
final class OnlinePaymentInitiator implements PaymentInitiator
{
    public function __construct(private readonly InitiatePayment $initiate) {}

    public function supports(string $methodCode): bool
    {
        return PaymentMethod::query()->where('code', $methodCode)->where('type', '!=', 'cod')->exists();
    }

    public function initiate(OrderPaymentRequest $order): string
    {
        return $this->initiate->handle($order);
    }
}
