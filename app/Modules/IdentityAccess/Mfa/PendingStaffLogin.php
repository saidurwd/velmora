<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Mfa;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Session\Session;

/**
 * Between "password correct" and "second factor correct" nothing is signed in. This holds only the user id, in the
 * session, for five minutes. The admin guard is logged in solely after MFA succeeds.
 */
final class PendingStaffLogin
{
    private const KEY = 'admin.pending_login';

    private const TTL_SECONDS = 300;

    public function __construct(private readonly Session $session, private readonly Clock $clock) {}

    public function start(int $userId): void
    {
        $this->session->put(self::KEY, ['user_id' => $userId, 'at' => $this->clock->now()->getTimestamp()]);
    }

    public function userId(): ?int
    {
        /** @var array{user_id: int, at: int}|null $state */
        $state = $this->session->get(self::KEY);

        if (! is_array($state) || $this->clock->now()->getTimestamp() - $state['at'] > self::TTL_SECONDS) {
            $this->clear();

            return null;
        }

        return $state['user_id'];
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }
}
