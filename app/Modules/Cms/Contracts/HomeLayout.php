<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Cms\DTOs\HomeSectionView;

/** The home page's blocks that staff have set up, visible right now and in order. Empty when nothing is configured: the home page then keeps its built-in layout. */
interface HomeLayout
{
    /** @return list<HomeSectionView> */
    public function sections(string $locale): array;
}
