<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\DTOs\CartView;

/** Read access for other modules (checkout). */
interface CartReader
{
    public function view(CartOwner $owner, string $locale): CartView;

    /**
     * The cart as it would look with these codes instead of the ones saved (used to test a code before keeping it).
     *
     * @param  list<string>  $codes
     */
    public function viewWith(CartOwner $owner, string $locale, array $codes): CartView;

    public function itemCount(CartOwner $owner): int;
}
