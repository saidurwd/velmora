<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\SearchHit;

/** One kind of thing the staff search box can find. Each module registers its own with the SearchProviders registry. */
interface SearchProvider
{
    /** The translation key of the heading for this kind of result. */
    public function kind(): string;

    /**
     * Every permission the person must hold to get these results.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /**
     * Exact or prefix matches on indexed columns only, at most $limit.
     *
     * @return list<SearchHit>
     */
    public function search(string $term, int $limit, bool $seePii): array;
}
