<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Enums;

/** What is being booked: a person, a room, a vehicle or a team. */
enum ResourceType: string
{
    case Staff = 'staff';
    case Room = 'room';
    case Vehicle = 'vehicle';
    case Team = 'team';
}
