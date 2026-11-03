<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\BookingAccess;
use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** The service was delivered: staff, or the customer for their own booking, say so once it has started. One conditional update; completing twice completes once. */
final class CompleteBooking
{
    public function __construct(private readonly BookingAccess $access, private readonly BookingEvents $events, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws BookingException not_found | not_completable | not_started */
    public function handle(Actor $actor, string $bookingPublicId): void
    {
        Transactions::run(function () use ($actor, $bookingPublicId): void {
            $b = $this->access->lock($actor, $bookingPublicId);
            if (! in_array($b->status, ['confirmed', 'in_progress'], true)) {
                throw new BookingException('not_completable');
            }
            $now = $this->clock->now();
            if (strtotime((string) $b->starts_at.' UTC') > $now->getTimestamp()) {
                throw new BookingException('not_started');
            }
            $changed = DB::table('service_bookings')->where('id', $b->id)->where('status', $b->status)->update(['status' => 'completed', 'completed_at' => $now, 'completed_by' => $actor->type->value, 'updated_at' => $now]);
            if ($changed !== 1) {
                throw new BookingException('not_completable');
            }
            $this->events->add((int) $b->id, 'completed', $actor->type->value, $actor->id);
            $this->audit->record('bookings.completed', 'booking', [], ['by' => $actor->type->value], $actor, (int) $b->id);
        });
    }
}
