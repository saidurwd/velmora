<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Auth;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * "This sign-in confirmed it is the person very recently", for sensitive changes from the app (the web keeps the same mark in its session).
 * It belongs to one app sign-in (the family), not to a token, so it survives a refresh; it lasts `api.tokens.reauth_minutes`. Losing it only
 * means the person is asked again.
 */
final class ApiReauth
{
    public function __construct(private readonly Cache $cache, private readonly Clock $clock) {}

    public function mark(string $family): void
    {
        $this->cache->put($this->key($family), $this->clock->now()->getTimestamp(), $this->window());
    }

    public function isFresh(string $family): bool
    {
        $at = $this->cache->get($this->key($family));

        return is_int($at) && $this->clock->now()->getTimestamp() - $at <= $this->window();
    }

    private function key(string $family): string
    {
        return 'api-reauth:'.$family;
    }

    private function window(): int
    {
        return max(1, (int) config('api.tokens.reauth_minutes')) * 60;
    }
}
