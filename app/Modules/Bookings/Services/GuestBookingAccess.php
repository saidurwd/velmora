<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use Illuminate\Contracts\Session\Session;

/** A guest who proved they hold the order's phone may manage that ONE order's bookings for a while. Kept in their own session, nowhere else. */
final class GuestBookingAccess
{
    public function __construct(private readonly Session $session) {}

    public function grant(string $orderPublicId): void
    {
        $grants = (array) $this->session->get('bookings.guest', []);
        $grants[$orderPublicId] = now()->addMinutes((int) config('bookings.guest_minutes'))->getTimestamp();
        $this->session->put('bookings.guest', $grants);
    }

    public function has(string $orderPublicId): bool
    {
        return (int) (((array) $this->session->get('bookings.guest', []))[$orderPublicId] ?? 0) > now()->getTimestamp();
    }
}
