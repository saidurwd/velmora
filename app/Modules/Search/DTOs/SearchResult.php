<?php

declare(strict_types=1);

namespace App\Modules\Search\DTOs;

/** Matching product ids, best first. `correctedQuery` is set when typo correction found results for a different spelling. */
final readonly class SearchResult
{
    /** @param  list<int>  $productIds */
    public function __construct(public string $normalizedQuery, public array $productIds, public ?string $correctedQuery = null) {}
}
