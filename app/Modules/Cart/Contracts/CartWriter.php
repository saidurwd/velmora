<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;

/** What checkout may do to a cart: empty it once the order is safely written. */
interface CartWriter
{
    public function clear(CartOwner $owner): void;
}
