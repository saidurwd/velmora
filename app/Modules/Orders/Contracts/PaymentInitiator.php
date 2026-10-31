<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use DomainException;

/**
 * Starts payment for one payment method after the order exists. Cash on delivery has nothing to start. Online methods are
 * provided by the Payments module and return the address to send the shopper to; success is accepted only after the
 * server-to-server verification there, never from anything the browser brings back.
 */
interface PaymentInitiator
{
    /** Whether this starts payment for the payment method with this code. */
    public function supports(string $methodCode): bool;

    /**
     * @return string|null where to send the shopper next, or null to go straight to the confirmation page
     *
     * @throws DomainException when payment could not be started (the order stays unpaid and the shopper can try again)
     */
    public function initiate(OrderPaymentRequest $order): ?string;
}
