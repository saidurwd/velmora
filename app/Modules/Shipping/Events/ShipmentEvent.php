<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/**
 * Base of the parcel events other modules react to. Written to the outbox in the same transaction as the change they announce.
 * `detail` adds what the receiver needs: for "delivered", `all_delivered` when this parcel completed the order; for "failed", `final` when the
 * attempts allowed are used up.
 */
abstract class ShipmentEvent extends DomainEvent
{
    final public function __construct(
        public readonly int $shipmentId,
        public readonly string $shipmentPublicId,
        public readonly int $orderId,
        public readonly string $orderPublicId,
        public readonly int $codAmountMinor,
        public readonly string $currency,
        public readonly string $occurredAt,
        public readonly int $attempts = 0,
        public readonly ?string $detail = null,
    ) {}

    public function toPayload(): array
    {
        return ['shipment_id' => $this->shipmentId, 'shipment_public_id' => $this->shipmentPublicId, 'order_id' => $this->orderId, 'order_public_id' => $this->orderPublicId, 'cod_amount_minor' => $this->codAmountMinor, 'currency' => $this->currency, 'occurred_at' => $this->occurredAt, 'attempts' => $this->attempts, 'detail' => $this->detail];
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) $payload['shipment_id'], (string) $payload['shipment_public_id'], (int) $payload['order_id'], (string) $payload['order_public_id'], (int) $payload['cod_amount_minor'], (string) $payload['currency'], (string) $payload['occurred_at'], (int) ($payload['attempts'] ?? 0), $payload['detail'] ?? null);
    }
}
