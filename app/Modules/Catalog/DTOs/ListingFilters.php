<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;

/**
 * Filters and sort of a listing page, parsed leniently from the query string: anything malformed is ignored rather than
 * rejected, so a shared or hand-edited URL never errors out. The URL is the single source of filter state.
 */
final readonly class ListingFilters
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'popular'];

    public const TYPES = ['physical', 'digital', 'service'];

    /**
     * @param  list<string>  $brands  brand slugs
     * @param  list<string>  $types
     * @param  array<string, list<string>>  $attributes  attribute code => value codes
     */
    public function __construct(
        public array $brands = [],
        public ?int $minMinor = null,
        public ?int $maxMinor = null,
        public array $types = [],
        public bool $inStock = false,
        public array $attributes = [],
        public string $sort = 'newest',
        public int $page = 1,
    ) {}

    /** @param  array<string, mixed>  $q */
    public static function fromQuery(array $q): self
    {
        $strings = fn (mixed $v): array => array_values(array_unique(array_filter(array_map(fn ($s) => is_string($s) ? strtolower(trim($s)) : '', is_array($v) ? $v : []), fn ($s) => $s !== '' && strlen($s) <= 80 && preg_match('/^[\p{L}\p{N}_\-]+$/u', $s) === 1)));

        $attributes = [];
        foreach (is_array($q['attr'] ?? null) ? array_slice($q['attr'], 0, 10, true) : [] as $code => $values) {
            $code = strtolower((string) $code);
            if (preg_match('/^[a-z0-9_\-]{1,60}$/', $code) === 1 && ($list = $strings($values)) !== []) {
                $attributes[$code] = array_slice($list, 0, 20);
            }
        }

        $min = self::money($q['min'] ?? null);
        $max = self::money($q['max'] ?? null);
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $sort = is_string($q['sort'] ?? null) && in_array($q['sort'], self::SORTS, true) ? $q['sort'] : 'newest';
        $page = is_scalar($q['page'] ?? null) && ctype_digit((string) $q['page']) ? min(10_000, max(1, (int) $q['page'])) : 1;

        return new self(
            array_slice($strings($q['brand'] ?? []), 0, 20),
            $min, $max,
            array_values(array_intersect($strings($q['type'] ?? []), self::TYPES)),
            filter_var($q['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $attributes, $sort, $page,
        );
    }

    /** True when any narrowing filter is active (sort and page do not count). */
    public function isFiltered(): bool
    {
        return $this->brands !== [] || $this->minMinor !== null || $this->maxMinor !== null || $this->types !== [] || $this->inStock || $this->attributes !== [];
    }

    private static function money(mixed $value): ?int
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            $minor = Money::fromMajorString(BanglaText::numeric((string) $value))->amountMinor;
        } catch (MoneyException) {
            return null;
        }

        return $minor >= 0 && $minor <= 100_000_000_00 ? $minor : null;
    }
}
