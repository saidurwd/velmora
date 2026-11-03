<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\BookingAccess;
use App\Modules\Bookings\Services\BookingEnder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Transactions;

/**
 * Ends a confirmed booking by cancelling it. A customer may cancel only their own booking and only before it starts; what they pay comes from the terms the
 * booking was confirmed under. Staff may cancel with a reason and may waive the fee (with a reason). Nothing is refunded here: the amount due is recorded and
 * staff approve the refund the usual way. Cancelling twice, or at the same moment from two places, ends it once.
 */
final class CancelBooking
{
    public function __construct(private readonly BookingAccess $access, private readonly BookingEnder $ender, private readonly Clock $clock) {}

    /**
     * @return \stdClass the money outcome (fee, refund due, ...)
     *
     * @throws BookingException not_found | not_cancellable | already_started | reason_required | waive_staff_only
     */
    public function handle(Actor $actor, string $bookingPublicId, ?string $reason = null, bool $waiveFee = false): \stdClass
    {
        $customer = $actor->type === ActorType::Customer;
        if ($waiveFee && $customer) {
            throw new BookingException('waive_staff_only');
        }
        if (! $customer && trim((string) $reason) === '') {
            throw new BookingException('reason_required');   // staff always say why
        }

        return Transactions::run(function () use ($actor, $bookingPublicId, $reason, $waiveFee, $customer): \stdClass {
            $booking = $this->access->lock($actor, $bookingPublicId);
            if ($booking->status !== 'confirmed') {
                throw new BookingException('not_cancellable');
            }
            if ($customer && strtotime((string) $booking->starts_at.' UTC') <= $this->clock->now()->getTimestamp()) {
                throw new BookingException('already_started');
            }

            return $this->ender->end($booking, 'cancelled', $actor, $waiveFee, $reason);
        });
    }
}
