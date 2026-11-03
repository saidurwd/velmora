<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

/** A running booking nobody completed or marked as a no-show is completed by the system some hours after it ended (24 by default; 0 switches it off). */
final class AutoCompleteBookings
{
    public function __construct(private readonly BookingEvents $events, private readonly Settings $settings, private readonly Clock $clock) {}

    /** @return int how many bookings were completed now */
    public function handle(int $limit = 500): int
    {
        $hours = $this->settings->int('bookings.auto_complete_hours', (int) config('bookings.auto_complete_hours'));
        if ($hours <= 0) {
            return 0;
        }

        $now = $this->clock->now();
        $cutoff = $now->subHours($hours);
        $done = 0;
        foreach (DB::table('service_bookings')->where('status', 'in_progress')->where('ends_at', '<=', $cutoff)->orderBy('id')->limit($limit)->pluck('id') as $id) {   // index (status, ends_at)
            if (DB::table('service_bookings')->where('id', $id)->where('status', 'in_progress')->where('ends_at', '<=', $cutoff)->update(['status' => 'completed', 'completed_at' => $now, 'completed_by' => 'system', 'updated_at' => $now]) === 1) {
                $this->events->add((int) $id, 'auto_completed');
                $done++;
            }
        }

        return $done;
    }
}
