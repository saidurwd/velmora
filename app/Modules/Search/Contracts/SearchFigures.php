<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\DTOs\SearchGap;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. Search text is typed by customers and may hold anything, so the caller decides what may be shown. */
interface SearchFigures
{
    /**
     * Searches that found nothing from `$from` (included) to `$to` (excluded), the same words in the same language added together, only those searched at least twice,
     * most searched first (ties: the words in order), at most `$limit`.
     *
     * @return list<SearchGap>
     */
    public function repeatedNoResults(CarbonImmutable $from, CarbonImmutable $to, int $limit): array;

    /** How many searches found nothing from `$from` (included) to `$to` (excluded), every one counted. */
    public function noResultTotal(CarbonImmutable $from, CarbonImmutable $to): int;
}
