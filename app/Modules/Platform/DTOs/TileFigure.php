<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/**
 * What a dashboard tile shows: a count or an amount of money (minor units), the list it links to (a route name and its query), what the same figure was before
 * (for "today against yesterday") and a short series for a trend. `capped` means the real number is at least this big.
 */
final readonly class TileFigure
{
    /**
     * @param  'count'|'money'  $kind
     * @param  array<string, string>  $params  the query of the list the tile links to
     * @param  list<int>  $series  oldest first
     */
    public function __construct(public int $value, public string $kind, public string $route, public array $params = [], public bool $capped = false, public ?int $before = null, public array $series = []) {}
}
