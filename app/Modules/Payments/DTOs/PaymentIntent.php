<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Platform\DTOs\Money;

/** Everything a gateway needs to start taking a payment. Built by InitiatePayment from the order; the adapter turns it into the provider's fields. */
final readonly class PaymentIntent
{
    /**
     * @param  array<string, string>  $meta  small values the gateway hands back unchanged (for example the payment's public id)
     */
    public function __construct(
        public string $merchantRef,
        public Money $amount,
        public string $orderNumber,
        public string $customerName,
        public string $customerPhone,
        public ?string $customerEmail,
        public string $addressLine,
        public string $city,
        public ?string $postcode,
        public int $itemCount,
        public bool $needsShipping,
        public string $productSummary,
        public string $successUrl,
        public string $failUrl,
        public string $cancelUrl,
        public string $notifyUrl,
        public array $meta = [],
    ) {}
}
