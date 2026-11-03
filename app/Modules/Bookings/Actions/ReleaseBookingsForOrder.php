<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** A cancelled order gives its still-held times back. A confirmed booking is not touched here: ending a paid booking is a cancellation with its own rules. */
final class ReleaseBookingsForOrder
{
    public function __construct(private readonly ServiceOrderLines $lines, private readonly BookingEvents $events, private readonly Clock $clock) {}

    /** @return int how many held times were given back now */
    public function handle(int $orderId): int
    {
        $ids = array_map(fn ($l) => $l->orderItemId, $this->lines->linesFor($orderId));
        if ($ids === []) {
            return 0;
        }

        $released = 0;
        foreach (DB::table('service_bookings')->whereIn('order_item_id', $ids)->where('status', 'held')->orderBy('id')->pluck('id') as $id) {
            if (DB::table('service_bookings')->where('id', $id)->where('status', 'held')->update(['status' => 'cancelled', 'updated_at' => $this->clock->now()]) === 1) {
                $this->events->add((int) $id, 'order_cancelled');
                $released++;
            }
        }

        return $released;
    }
}
