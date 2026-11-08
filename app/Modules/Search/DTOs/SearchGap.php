<?php

declare(strict_types=1);

namespace App\Modules\Search\DTOs;

use Carbon\CarbonImmutable;

/** A search that found nothing, as typed (normalised), in one language: how many times in a period and when last. */
final readonly class SearchGap
{
    public function __construct(public string $query, public string $locale, public int $times, public CarbonImmutable $lastAt) {}
}
