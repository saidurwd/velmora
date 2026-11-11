<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** One search result: what to call it, one line of detail (already masked where it must be) and where it opens. */
final readonly class SearchHit
{
    public function __construct(public string $title, public string $detail, public string $url) {}
}
