<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\BookingAccess;
use App\Modules\Bookings\Services\BookingEnder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Transactions;

/** Staff record that the customer did not turn up. Only once the start time has come, and only for a confirmed or running booking. The no-show fee comes from the booking's own terms and may be waived. */
final class MarkNoShow
{
    public function __construct(private readonly BookingAccess $access, private readonly BookingEnder $ender, private readonly Clock $clock) {}

    /**
     * @return \stdClass the money outcome (fee, refund due, ...)
     *
     * @throws BookingException not_found | not_cancellable | no_show_too_early
     */
    public function handle(Actor $actor, string $bookingPublicId, ?string $reason = null, bool $waiveFee = false): \stdClass
    {
        if (! $actor->isStaff()) {
            throw new BookingException('not_found');
        }

        return Transactions::run(function () use ($actor, $bookingPublicId, $reason, $waiveFee): \stdClass {
            $booking = $this->access->lock($actor, $bookingPublicId);
            if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
                throw new BookingException('not_cancellable');
            }
            if (strtotime((string) $booking->starts_at.' UTC') > $this->clock->now()->getTimestamp()) {
                throw new BookingException('no_show_too_early');
            }

            return $this->ender->end($booking, 'no_show', $actor, $waiveFee, $reason);
        });
    }
}
