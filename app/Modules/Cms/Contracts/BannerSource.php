<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Cms\DTOs\BannerView;

interface BannerSource
{
    /** @return list<BannerView> active, in-window banners for the placement, in staff-chosen order; falls back to the default language's image */
    public function active(string $placement, string $locale): array;
}
