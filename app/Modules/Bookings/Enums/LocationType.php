<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Enums;

/** Where a service happens. Visits to the customer's address come later. */
enum LocationType: string
{
    case AtProvider = 'at_provider';
    case Online = 'online';
}
