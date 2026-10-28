<?php

declare(strict_types=1);

namespace App\Modules\Platform\Outbox;

/**
 * Base for events that must survive a crash between commit and dispatch (ADR-017).
 * Subclasses live in the producing module's Events/ namespace and are registered with OutboxRegistry.
 * `outboxId` is set by the dispatcher so listeners can use IdempotentConsumer.
 */
abstract class DomainEvent
{
    public ?int $outboxId = null;

    /** Stable name stored in the outbox, e.g. "orders.placed". Never rename once deployed. */
    abstract public static function eventName(): string;

    /** @return array<string, mixed> JSON-safe payload (ids and scalars, no models) */
    abstract public function toPayload(): array;

    /** @param  array<string, mixed>  $payload */
    abstract public static function fromPayload(array $payload): static;
}
