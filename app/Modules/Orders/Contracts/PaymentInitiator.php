<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Models\Order;

/**
 * Starts payment for one payment method after the order exists. Cash on delivery has nothing to start. Online methods
 * (Phase 6, SSLCommerz) return a URL to send the shopper to; success is accepted only after server-to-server verification.
 */
interface PaymentInitiator
{
    public function method(): string;

    /** @return string|null where to send the shopper next, or null to go straight to the confirmation page */
    public function initiate(Order $order): ?string;
}
