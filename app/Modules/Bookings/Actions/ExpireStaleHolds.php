<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Gives back the time of holds that ran out: the booking becomes cancelled and says why in its history. Every minute, and safe to run at any time, by anyone,
 * any number of times: a hold is only ever ended while it is still held and its time has passed.
 */
final class ExpireStaleHolds
{
    public function __construct(private readonly BookingEvents $events, private readonly Clock $clock) {}

    /** @return int how many holds were ended now */
    public function handle(int $limit = 500): int
    {
        $now = $this->clock->now();
        $ended = 0;
        foreach (DB::table('service_bookings')->where('status', 'held')->where('hold_expires_at', '<=', $now)->orderBy('id')->limit($limit)->pluck('id') as $id) {   // index (status, hold_expires_at)
            if (DB::table('service_bookings')->where('id', $id)->where('status', 'held')->where('hold_expires_at', '<=', $now)->update(['status' => 'cancelled', 'updated_at' => $now]) === 1) {
                $this->events->add((int) $id, 'hold_expired');
                $ended++;
            }
        }

        return $ended;
    }
}
