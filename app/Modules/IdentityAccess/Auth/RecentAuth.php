<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Auth;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Session\Session;

/** "Recently confirmed" marker for sensitive account changes (password, email, phone, deletion). */
final class RecentAuth
{
    private const KEY = 'auth.recently_confirmed_at';

    public const WINDOW_MINUTES = 10;

    public function __construct(private readonly Session $session, private readonly Clock $clock) {}

    public function mark(): void
    {
        $this->session->put(self::KEY, $this->clock->now()->getTimestamp());
    }

    public function isFresh(int $minutes = self::WINDOW_MINUTES): bool
    {
        $at = $this->session->get(self::KEY);

        return is_int($at) && $this->clock->now()->getTimestamp() - $at <= $minutes * 60;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
