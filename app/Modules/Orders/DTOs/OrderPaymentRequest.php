<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** What payment code needs to know about an order, and nothing more. Built from the order's own snapshot rows. */
final readonly class OrderPaymentRequest
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public string $orderNumber,
        public int $amountMinor,
        public string $currency,
        public string $methodCode,
        public string $customerName,
        public string $customerPhone,
        public ?string $customerEmail,
        public ?string $addressLine,
        public ?string $city,
        public ?string $postcode,
        public int $itemCount,
        public bool $needsShipping,
        public string $productSummary,
        public string $locale,
    ) {}
}
