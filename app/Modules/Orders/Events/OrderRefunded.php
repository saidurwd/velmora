<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** Money was given back for an order: all of it (`full`) or part of it. Written to the outbox in the same transaction as the change, so it is announced once. */
final class OrderRefunded extends DomainEvent
{
    public function __construct(public readonly int $orderId, public readonly string $publicId, public readonly string $orderNumber, public readonly bool $full) {}

    public static function eventName(): string
    {
        return 'orders.refunded';
    }

    public function toPayload(): array
    {
        return ['order_id' => $this->orderId, 'public_id' => $this->publicId, 'order_number' => $this->orderNumber, 'full' => $this->full];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['order_id'], (string) $payload['public_id'], (string) $payload['order_number'], (bool) $payload['full']);
    }
}
