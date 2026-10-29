<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\DTOs\CartView;

/** Read access for other modules (checkout). */
interface CartReader
{
    public function view(CartOwner $owner, string $locale): CartView;

    public function itemCount(CartOwner $owner): int;
}
