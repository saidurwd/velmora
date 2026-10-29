<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** A customer has placed an order. Written to the outbox in the same transaction as the order, so it is never lost or announced for an order that does not exist. */
final class OrderPlaced extends DomainEvent
{
    public function __construct(public readonly int $orderId, public readonly string $publicId, public readonly string $orderNumber, public readonly string $locale) {}

    public static function eventName(): string
    {
        return 'orders.placed';
    }

    public function toPayload(): array
    {
        return ['order_id' => $this->orderId, 'public_id' => $this->publicId, 'order_number' => $this->orderNumber, 'locale' => $this->locale];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['order_id'], (string) $payload['public_id'], (string) $payload['order_number'], (string) $payload['locale']);
    }
}
