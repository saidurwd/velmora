<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartFillResult;
use App\Modules\Cart\DTOs\CartOwner;

/**
 * Puts a variant in someone's cart for another module (buy again, a saved item). Always at today's price and subject to the cart's own rules; it never throws for a line
 * that simply cannot go in: it says so in the result. Where only part of the quantity is available, the part that fits is added.
 */
interface CartFiller
{
    public function add(CartOwner $owner, int $variantId, int $quantity, string $locale): CartFillResult;
}
