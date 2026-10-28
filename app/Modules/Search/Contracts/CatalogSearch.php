<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\DTOs\SearchResult;
use App\Modules\Search\DTOs\Suggestion;

/** The one way to search the catalog. Today a MariaDB FULLTEXT engine; swappable (ADR-020) without touching callers. */
interface CatalogSearch
{
    public function search(string $query, string $locale, int $limit = 200): SearchResult;

    /** Records a search for merchandising (zero-result report). Stores only the normalised text, language and count: no user data. */
    public function logQuery(string $normalizedQuery, string $locale, int $resultCount): void;

    /** @return list<Suggestion> quick matches for the autocomplete box */
    public function suggest(string $query, string $locale, int $limit = 6): array;
}
