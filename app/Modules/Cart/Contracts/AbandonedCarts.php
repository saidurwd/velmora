<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\AbandonedCart;
use Carbon\CarbonImmutable;

/** Read access for the module that reminds customers (Engagement). Only signed-in customers' carts; a guest has no consent and no address. */
interface AbandonedCarts
{
    /**
     * Carts with at least one line whose newest change is at or before `$idleSince` and after `$notBefore`, oldest change first, a page at a time. An empty cart (for example after the
     * customer bought what was in it) is never listed.
     *
     * @return list<AbandonedCart>
     */
    public function idle(CarbonImmutable $idleSince, CarbonImmutable $notBefore, int $limit, int $offset = 0): array;
}
