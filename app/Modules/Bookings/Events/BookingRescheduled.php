<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** A confirmed booking was moved to another time or resource. Written to the outbox in the same transaction as the change. */
final class BookingRescheduled extends DomainEvent
{
    public function __construct(public readonly int $bookingId, public readonly string $publicId) {}

    public static function eventName(): string
    {
        return 'bookings.rescheduled';
    }

    public function toPayload(): array
    {
        return ['booking_id' => $this->bookingId, 'public_id' => $this->publicId];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['booking_id'], (string) $payload['public_id']);
    }
}
