<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** Emitted once when available stock for a variant at a location crosses down to its low-stock threshold. */
final class StockLow extends DomainEvent
{
    public function __construct(public readonly int $variantId, public readonly int $locationId, public readonly int $available, public readonly int $threshold) {}

    public static function eventName(): string
    {
        return 'inventory.stock_low';
    }

    public function toPayload(): array
    {
        return ['variant_id' => $this->variantId, 'location_id' => $this->locationId, 'available' => $this->available, 'threshold' => $this->threshold];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['variant_id'], (int) $payload['location_id'], (int) $payload['available'], (int) $payload['threshold']);
    }
}
