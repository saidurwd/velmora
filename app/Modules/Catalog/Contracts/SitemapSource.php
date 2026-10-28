<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\SitemapEntry;

interface SitemapSource
{
    /** @return iterable<SitemapEntry> every public catalog page: shop, active categories, brands, collections, live products */
    public function entries(): iterable;
}
