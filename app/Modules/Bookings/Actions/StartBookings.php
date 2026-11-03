<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** A confirmed booking whose start time has come is now under way (it still holds its resource). One conditional update per booking; safe to repeat. */
final class StartBookings
{
    public function __construct(private readonly BookingEvents $events, private readonly Clock $clock) {}

    /** @return int how many bookings were started now */
    public function handle(int $limit = 500): int
    {
        $now = $this->clock->now();
        $started = 0;
        foreach (DB::table('service_bookings')->where('status', 'confirmed')->where('starts_at', '<=', $now)->orderBy('id')->limit($limit)->pluck('id') as $id) {   // index (status, starts_at)
            if (DB::table('service_bookings')->where('id', $id)->where('status', 'confirmed')->where('starts_at', '<=', $now)->update(['status' => 'in_progress', 'updated_at' => $now]) === 1) {
                $this->events->add((int) $id, 'started');
                $started++;
            }
        }

        return $started;
    }
}
