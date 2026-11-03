<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Bookings\Services\CancellationPolicies;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A paid order confirms its booked times. Each booking is locked and confirmed only if it is still held and has not run out. A booking that is already
 * confirmed is left alone (so a repeat does nothing). One that ran out first is NOT confirmed: the payment came too late, so it is ended, the history says so,
 * and an alert is logged for staff to rebook the customer or refund. Nothing is refunded here, and an ended time is never brought back.
 */
final class ConfirmBookingsForOrder
{
    public function __construct(private readonly ServiceOrderLines $lines, private readonly BookingEvents $events, private readonly Clock $clock, private readonly CancellationPolicies $policies) {}

    /** @return int how many bookings were confirmed now */
    public function handle(int $orderId): int
    {
        $lines = $this->lines->paidLinesFor($orderId);
        if ($lines === []) {
            return 0;
        }

        return Transactions::run(function () use ($lines, $orderId): int {
            $now = $this->clock->now();
            $confirmed = 0;
            foreach (DB::table('service_bookings')->whereIn('order_item_id', array_map(fn ($l) => $l->orderItemId, $lines))->orderBy('id')->lockForUpdate()->get(['id', 'status', 'hold_expires_at', 'package_id']) as $b) {
                if ($b->status === 'confirmed') {
                    continue;
                }
                if ($b->status === 'held' && $b->hold_expires_at > $now->format('Y-m-d H:i:s.u')) {
                    $confirmed += DB::table('service_bookings')->where('id', $b->id)->where('status', 'held')->update([
                        'status' => 'confirmed', 'hold_expires_at' => null, 'updated_at' => $now,
                        'policy_terms' => json_encode($this->policies->termsForService((int) DB::table('service_packages')->where('id', $b->package_id)->value('service_id')), JSON_THROW_ON_ERROR),   // the deal as it is today
                    ]);
                    $this->events->add((int) $b->id, 'confirmed', 'system', null, 'payment received');

                    continue;
                }
                if ($b->status === 'held') {
                    DB::table('service_bookings')->where('id', $b->id)->where('status', 'held')->update(['status' => 'cancelled', 'updated_at' => $now]);
                    $this->events->add((int) $b->id, 'hold_expired');
                }
                if ($b->status === 'held' || $b->status === 'cancelled') {
                    $this->events->add((int) $b->id, 'payment_after_expiry', 'system', null, 'paid after the time was given up; not confirmed');
                    Log::error('bookings.alert', ['reason' => 'payment_after_expiry', 'booking_id' => (int) $b->id, 'order_id' => $orderId]);
                }
            }

            return $confirmed;
        });
    }
}
