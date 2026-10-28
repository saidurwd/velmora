<?php

declare(strict_types=1);

namespace App\Modules\Cms\DTOs;

final readonly class PageLink
{
    public function __construct(public string $slug, public string $title, public string $locale) {}
}
