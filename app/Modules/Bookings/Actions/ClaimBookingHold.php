<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Contracts\BookingHolds;
use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Platform\Contracts\Clock;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The visitor's hold becomes the booking of an order line, decided by one conditional UPDATE: still held, not run out, not yet claimed, and for this very
 * product. Two orders can never claim the same hold, and a hold the sweeper has ended can never be claimed. The hold is never shortened, only kept at least
 * for the payment window so the customer can pay.
 */
final class ClaimBookingHold implements BookingHolds
{
    public function __construct(private readonly BookingEvents $events, private readonly Clock $clock) {}

    public function claim(string $holdRef, int $variantId, int $orderItemId): void
    {
        $now = $this->clock->now();
        $until = $now->addMinutes((int) config('bookings.payment_hold_minutes'));
        $changed = DB::table('service_bookings')
            ->where('public_id', $holdRef)->where('status', 'held')->whereNull('order_item_id')->where('hold_expires_at', '>', $now)
            ->whereIn('package_id', DB::table('service_packages')->where('variant_id', $variantId)->select('id'))
            ->update(['order_item_id' => $orderItemId, 'updated_at' => $now]);
        if ($changed !== 1) {
            throw new DomainException('hold_lost');
        }
        $mine = DB::table('service_bookings')->where('order_item_id', $orderItemId);
        (clone $mine)->where('hold_expires_at', '<', $until)->update(['hold_expires_at' => $until]);   // never shortened, only kept for the payment window
        $this->events->add((int) $mine->value('id'), 'ordered');
    }
}
