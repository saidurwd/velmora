<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use Illuminate\Contracts\Session\Session;

/** A guest who proved they hold the order's phone may use that ONE order's downloads for a while. Kept in their own session, nowhere else. */
final class GuestDownloadAccess
{
    public function __construct(private readonly Session $session) {}

    public function grant(string $orderPublicId): void
    {
        $grants = (array) $this->session->get('digital.guest', []);
        $grants[$orderPublicId] = now()->addMinutes((int) config('digital.guest_minutes'))->getTimestamp();
        $this->session->put('digital.guest', $grants);
    }

    public function has(string $orderPublicId): bool
    {
        $until = ((array) $this->session->get('digital.guest', []))[$orderPublicId] ?? 0;

        return (int) $until > now()->getTimestamp();
    }
}
