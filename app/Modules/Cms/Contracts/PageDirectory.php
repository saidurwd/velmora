<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Cms\DTOs\PageLink;

interface PageDirectory
{
    /** @return list<PageLink> published pages that exist in this language (footer links, sitemaps) */
    public function published(string $locale): array;
}
