<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;

/** Lines of a cart that are a booking: one per service, quantity one, each carrying the held slot it stands for. Used by the module that holds the slots. */
interface HeldCartLines
{
    /**
     * Puts the booking in the cart, replacing the one already there for this service. The price is read here, never accepted from a caller.
     *
     * @throws CartException when the item has no price or cannot be bought
     */
    public function put(CartOwner $owner, int $variantId, string $holdRef): void;

    /** The held slot a variant's line in this cart stands for, if any. */
    public function refFor(CartOwner $owner, int $variantId): ?string;
}
