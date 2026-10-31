<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** Base of the payment events other modules react to. Written to the outbox in the same transaction as the change they announce. */
abstract class PaymentEvent extends DomainEvent
{
    final public function __construct(
        public readonly int $paymentId,
        public readonly string $paymentPublicId,
        public readonly int $orderId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $merchantRef = null,
        public readonly ?string $detail = null,
    ) {}

    public function toPayload(): array
    {
        return ['payment_id' => $this->paymentId, 'payment_public_id' => $this->paymentPublicId, 'order_id' => $this->orderId, 'amount_minor' => $this->amountMinor, 'currency' => $this->currency, 'merchant_ref' => $this->merchantRef, 'detail' => $this->detail];
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) $payload['payment_id'], (string) $payload['payment_public_id'], (int) $payload['order_id'], (int) $payload['amount_minor'], (string) $payload['currency'], $payload['merchant_ref'] ?? null, $payload['detail'] ?? null);
    }
}
