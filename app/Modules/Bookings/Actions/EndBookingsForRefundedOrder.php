<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingEnder;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * An order refunded in full has nothing left to deliver: its confirmed bookings that have not started give their time back. The customer already got
 * everything, so no fee and nothing more is due. A booking that has already started is left to staff (completed, or no-show).
 */
final class EndBookingsForRefundedOrder
{
    public function __construct(private readonly ServiceOrderLines $lines, private readonly BookingEnder $ender, private readonly Clock $clock) {}

    /** @return int how many bookings were ended now */
    public function handle(int $orderId): int
    {
        $ids = array_map(fn ($l) => $l->orderItemId, $this->lines->linesFor($orderId));
        if ($ids === []) {
            return 0;
        }

        $ended = 0;
        foreach (DB::table('service_bookings')->whereIn('order_item_id', $ids)->where('status', 'confirmed')->orderBy('id')->pluck('public_id') as $publicId) {
            $ended += Transactions::run(function () use ($publicId): int {
                $b = DB::table('service_bookings')->where('public_id', $publicId)->lockForUpdate()->first();
                if ($b === null || $b->status !== 'confirmed' || strtotime((string) $b->starts_at.' UTC') <= $this->clock->now()->getTimestamp()) {
                    return 0;
                }
                $this->ender->end($b, 'cancelled', Actor::system('order-refunded'), true, 'order refunded in full', true);

                return 1;
            });
        }

        return $ended;
    }
}
