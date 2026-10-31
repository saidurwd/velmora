<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\PaymentInitiator;
use App\Modules\Orders\DTOs\OrderPaymentRequest;

/** Finds whoever starts payment for a method. Modules add theirs by tagging it `orders.payment_initiators`. */
final class PaymentInitiators
{
    /** @param  iterable<PaymentInitiator>  $initiators */
    public function __construct(private readonly iterable $initiators) {}

    /** @throws \DomainException when payment could not be started */
    public function start(OrderPaymentRequest $order): ?string
    {
        foreach ($this->initiators as $initiator) {
            if ($initiator->supports($order->methodCode)) {
                return $initiator->initiate($order);
            }
        }

        return null; // a method with nothing to start (cash)
    }
}
