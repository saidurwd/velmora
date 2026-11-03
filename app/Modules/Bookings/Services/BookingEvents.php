<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** Writes what happened to a booking into its history. Each row is kept exactly as written and never changed. */
final class BookingEvents
{
    public function __construct(private readonly Clock $clock) {}

    public function add(int $bookingId, string $event, string $actorType = 'system', ?int $actorId = null, ?string $note = null): void
    {
        DB::table('booking_events')->insert(['booking_id' => $bookingId, 'event' => $event, 'actor_type' => $actorType, 'actor_id' => $actorId, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_at' => $this->clock->now()]);
    }
}
