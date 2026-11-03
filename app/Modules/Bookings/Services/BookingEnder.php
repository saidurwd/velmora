<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Events\BookingEnded;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one place where a confirmed booking ends without being delivered (cancelled, or a no-show). It must run inside the caller's transaction, with the booking
 * row already locked. It works out the fee from the terms kept on the booking, ends the booking with ONE conditional UPDATE on the status it was read in, and
 * writes the money outcome (append-only), the history, the audit entry and the announcement. It never refunds: `refund_due_minor` is only advice for staff.
 */
final class BookingEnder
{
    public function __construct(
        private readonly ServiceOrderLines $lines, private readonly CancellationPolicies $policies, private readonly BookingEvents $events,
        private readonly Clock $clock, private readonly Outbox $outbox, private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $kind  cancelled | no_show
     * @param  bool  $waive  the fee is not charged
     * @param  bool  $alreadyRefunded  the customer already got everything back, so nothing more is due
     * @return \stdClass the money outcome
     *
     * @throws BookingException not_found | not_cancellable
     */
    public function end(\stdClass $booking, string $kind, Actor $actor, bool $waive, ?string $reason, bool $alreadyRefunded = false): \stdClass
    {
        $now = $this->clock->now();
        $line = $booking->order_item_id === null ? null : $this->lines->lineFor((int) $booking->order_item_id);
        if ($line === null) {
            throw new BookingException('not_found');
        }

        $minutes = (int) floor((strtotime((string) $booking->starts_at.' UTC') - $now->getTimestamp()) / 60);
        $terms = $this->policies->termsOf($booking);
        $feeBp = $kind === 'no_show' ? $terms['no_show_fee_bp'] : $this->policies->cancelFeeBp($terms, $minutes);
        $paid = $line->paidMinor;
        $fee = $waive ? 0 : $this->policies->feeOn($paid, $feeBp);
        $refundDue = $alreadyRefunded ? 0 : $paid - $fee;

        $changed = DB::table('service_bookings')->where('id', $booking->id)->where('status', $booking->status)->update(['status' => $kind === 'no_show' ? 'no_show' : 'cancelled', 'updated_at' => $now]);
        if ($changed !== 1) {
            throw new BookingException('not_cancellable');
        }

        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255);
        DB::table('booking_cancellations')->insert([
            'booking_id' => $booking->id, 'kind' => $kind, 'by_type' => $actor->type->value, 'by_id' => $actor->id, 'minutes_before' => $minutes, 'paid_minor' => $paid, 'fee_bp' => $feeBp,
            'fee_waived' => $waive, 'fee_minor' => $fee, 'refund_due_minor' => $refundDue, 'reason' => $reason, 'created_at' => $now,
        ]);
        $this->events->add((int) $booking->id, $kind === 'no_show' ? 'no_show' : 'cancelled', $actor->type->value, $actor->id, $reason);
        $this->outbox->record(new BookingEnded((int) $booking->id, (string) $booking->public_id, $kind, $refundDue));
        $this->audit->record("bookings.{$kind}", 'booking', [], ['paid_minor' => $paid, 'fee_minor' => $fee, 'refund_due_minor' => $refundDue, 'waived' => $waive, 'reason' => $reason], $actor, (int) $booking->id);

        return (object) ['kind' => $kind, 'minutes_before' => $minutes, 'paid_minor' => $paid, 'fee_bp' => $feeBp, 'fee_minor' => $fee, 'refund_due_minor' => $refundDue, 'fee_waived' => $waive];
    }
}
