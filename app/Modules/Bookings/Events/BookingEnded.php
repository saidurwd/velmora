<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** A confirmed booking ended without being delivered: cancelled, or the customer did not turn up. Written to the outbox in the same transaction as the change. */
final class BookingEnded extends DomainEvent
{
    public function __construct(public readonly int $bookingId, public readonly string $publicId, public readonly string $kind, public readonly int $refundDueMinor) {}

    public static function eventName(): string
    {
        return 'bookings.ended';
    }

    public function toPayload(): array
    {
        return ['booking_id' => $this->bookingId, 'public_id' => $this->publicId, 'kind' => $this->kind, 'refund_due_minor' => $this->refundDueMinor];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['booking_id'], (string) $payload['public_id'], (string) $payload['kind'], (int) $payload['refund_due_minor']);
    }
}
