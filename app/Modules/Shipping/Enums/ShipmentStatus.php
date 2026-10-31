<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** The life of one parcel. The same moves are enforced by a database trigger (see the migration and its test). */
enum ShipmentStatus: string
{
    case Created = 'created';
    case HandedOver = 'handed_over';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /**
     * Couriers skip scans and sometimes re-route, so a parcel may jump forward. It never goes back to "created", and delivered, returned and
     * cancelled are final. A parcel can be cancelled only before it is handed over.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Created => [self::HandedOver, self::InTransit, self::OutForDelivery, self::Delivered, self::Failed, self::Cancelled, self::Unknown],
            self::HandedOver => [self::InTransit, self::OutForDelivery, self::Delivered, self::Failed, self::Returned, self::Unknown],
            self::InTransit => [self::OutForDelivery, self::Delivered, self::Failed, self::Returned, self::Unknown],
            self::OutForDelivery => [self::InTransit, self::Delivered, self::Failed, self::Returned, self::Unknown],
            self::Failed => [self::InTransit, self::OutForDelivery, self::Delivered, self::Returned, self::Unknown],
            self::Unknown => [self::HandedOver, self::InTransit, self::OutForDelivery, self::Delivered, self::Failed, self::Returned],
            self::Delivered, self::Returned, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** The parcel has left the shop (so the order cannot simply be cancelled). */
    public function hasLeft(): bool
    {
        return ! in_array($this, [self::Created, self::Cancelled], true);
    }
}
