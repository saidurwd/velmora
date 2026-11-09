<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Cms\DTOs\MenuLink;

/** What the storefront shows in a menu ('header' or 'footer'): active items only, in order, in the visitor's language. Empty when staff have set nothing up. */
interface MenuReader
{
    /** @return list<MenuLink> */
    public function items(string $menu, string $locale): array;
}
