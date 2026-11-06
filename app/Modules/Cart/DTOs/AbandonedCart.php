<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use Carbon\CarbonImmutable;

/** A signed-in customer's cart that has lines in it and has not changed for a while. `changedAt` is when its newest line last changed: a cart that changes again is a new episode. */
final readonly class AbandonedCart
{
    public function __construct(public int $userId, public CarbonImmutable $changedAt) {}

    /** Names this episode of this cart (a reminder is sent once per episode). */
    public function stamp(): string
    {
        return $this->changedAt->utc()->format('Y-m-d\TH:i:s.u');
    }
}
