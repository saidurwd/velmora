<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Support\Facades\DB;

/**
 * Finds one booking and locks it for the caller's transaction, if this person may touch it. A customer reaches only a booking that belongs to one of their
 * orders; a booking of somebody else, a hold nobody ordered and a booking that does not exist all read alike: "not found". Staff and the system reach any.
 * A guest never reaches one here (a guest's own link comes with the customer pages).
 */
final class BookingAccess
{
    public function __construct(private readonly ServiceOrderLines $lines, private readonly CustomerOrders $orders) {}

    /** @throws BookingException not_found */
    public function lock(Actor $actor, string $publicId): \stdClass
    {
        $booking = DB::table('service_bookings')->where('public_id', $publicId)->lockForUpdate()->first();   // unique public_id
        if ($booking === null || ! in_array($actor->type, [ActorType::Customer, ActorType::Staff, ActorType::System], true)) {
            throw new BookingException('not_found');
        }

        if ($actor->type === ActorType::Customer) {
            $line = $booking->order_item_id === null ? null : $this->lines->lineFor((int) $booking->order_item_id);
            if ($line === null || $actor->id === null || ! $this->orders->owns($actor->id, $line->orderId)) {
                throw new BookingException('not_found');
            }
        }

        return $booking;
    }
}
