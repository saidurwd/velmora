<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Events;

final class ShipmentDelivered extends ShipmentEvent
{
    public static function eventName(): string
    {
        return 'shipping.delivered';
    }
}
